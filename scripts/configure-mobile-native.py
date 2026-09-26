#!/usr/bin/env python3
"""Apply the approved FOODEX native identity and push-capability scaffolding.

This script runs after `flutter create` in CI/release builds. It contains only
public application identity; signing material and Firebase credentials stay external.
"""

from __future__ import annotations

import argparse
import plistlib
import re
from pathlib import Path


IDENTITIES = {
    'customer': {
        'bundle_id': 'com.fiftysolution.foodex.customer',
        'label': 'FOODEX',
    },
    'driver': {
        'bundle_id': 'com.fiftysolution.foodex.driver',
        'label': 'FOODEX Driver',
    },
}


def patch_android(app_dir: Path, bundle_id: str) -> None:
    app = app_dir / 'android' / 'app'
    build_files = [app / 'build.gradle.kts', app / 'build.gradle']
    for path in build_files:
        if not path.exists():
            continue
        text = path.read_text()
        text = re.sub(r'namespace\s*=\s*["\'][^"\']+["\']', f'namespace = "{bundle_id}"', text)
        text = re.sub(r'applicationId\s*=\s*["\'][^"\']+["\']', f'applicationId = "{bundle_id}"', text)
        text = re.sub(r'applicationId\s+["\'][^"\']+["\']', f'applicationId "{bundle_id}"', text)
        path.write_text(text)

    main_activities = list((app / 'src' / 'main').rglob('MainActivity.kt'))
    if not main_activities:
        raise RuntimeError('Generated Android MainActivity.kt was not found')
    for path in main_activities:
        text = path.read_text()
        text = re.sub(r'^package\s+[^\s]+', f'package {bundle_id}', text, count=1, flags=re.M)
        if 'NotificationChannel' not in text:
            text = text.replace(
                "import io.flutter.embedding.android.FlutterActivity",
                "import android.app.NotificationChannel\n"
                "import android.app.NotificationManager\n"
                "import android.os.Build\n"
                "import android.os.Bundle\n"
                "import io.flutter.embedding.android.FlutterActivity",
            )
            text = re.sub(
                r'class MainActivity\s*:\s*FlutterActivity\(\)\s*',
                "class MainActivity : FlutterActivity() {\n"
                "    override fun onCreate(savedInstanceState: Bundle?) {\n"
                "        super.onCreate(savedInstanceState)\n"
                "        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {\n"
                "            val channel = NotificationChannel(\n"
                "                \"foodex_updates\",\n"
                "                \"FOODEX Updates\",\n"
                "                NotificationManager.IMPORTANCE_DEFAULT\n"
                "            )\n"
                "            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)\n"
                "        }\n"
                "    }\n"
                "}\n",
                text,
            )
        path.write_text(text)


def patch_ios(app_dir: Path, bundle_id: str, label: str) -> None:
    ios = app_dir / 'ios'
    pbxproj = ios / 'Runner.xcodeproj' / 'project.pbxproj'
    if not pbxproj.exists():
        raise RuntimeError('Generated iOS project.pbxproj was not found')
    text = pbxproj.read_text()

    def bundle_replacement(match: re.Match[str]) -> str:
        original = match.group(1)
        suffix = '.RunnerTests' if original.endswith('.RunnerTests') else ''
        return f'PRODUCT_BUNDLE_IDENTIFIER = {bundle_id}{suffix};'

    text = re.sub(r'PRODUCT_BUNDLE_IDENTIFIER = ([^;]+);', bundle_replacement, text)
    if 'CODE_SIGN_ENTITLEMENTS = Runner/Runner.entitlements;' not in text:
        text = text.replace(
            'CODE_SIGN_STYLE = Automatic;',
            'CODE_SIGN_STYLE = Automatic;\n\t\t\t\tCODE_SIGN_ENTITLEMENTS = Runner/Runner.entitlements;',
        )
    pbxproj.write_text(text)

    info = ios / 'Runner' / 'Info.plist'
    with info.open('rb') as stream:
        plist = plistlib.load(stream)
    plist['CFBundleDisplayName'] = label
    with info.open('wb') as stream:
        plistlib.dump(plist, stream, sort_keys=False)

    entitlements = ios / 'Runner' / 'Runner.entitlements'
    with entitlements.open('wb') as stream:
        plistlib.dump({'aps-environment': 'production'}, stream, sort_keys=False)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--app', required=True, choices=sorted(IDENTITIES))
    parser.add_argument('--app-dir', required=True, type=Path)
    args = parser.parse_args()
    identity = IDENTITIES[args.app]
    patch_android(args.app_dir, identity['bundle_id'])
    patch_ios(args.app_dir, identity['bundle_id'], identity['label'])
    print(f"{args.app}: {identity['bundle_id']}")


if __name__ == '__main__':
    main()
