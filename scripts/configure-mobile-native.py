#!/usr/bin/env python3
"""Apply the approved FOODEX native identity, branding, and push scaffolding.

This script runs after flutter create in CI/release builds. It contains only
public application identity and approved brand assets; signing material and
Firebase credentials stay external.
"""

from __future__ import annotations

import argparse
import json
import plistlib
import re
import shutil
import subprocess
import xml.etree.ElementTree as ET
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

REPO_ROOT = Path(__file__).resolve().parents[1]
BRAND_ROOT = REPO_ROOT / 'assets' / 'mobile_brand'
APP_ICON = BRAND_ROOT / 'app_icon_1024.png'
APP_ICON_FOREGROUND = BRAND_ROOT / 'app_icon_foreground_1024.png'
SPLASH_IMAGE = BRAND_ROOT / 'splash_master_1290x2796.png'
SPLASH_BACKGROUND = '#003223'
SPLASH_ACCENT = '#92D853'


def _require_brand_assets() -> None:
    missing = [
        path
        for path in (APP_ICON, APP_ICON_FOREGROUND, SPLASH_IMAGE)
        if not path.is_file()
    ]
    if missing:
        names = ', '.join(str(path.relative_to(REPO_ROOT)) for path in missing)
        raise RuntimeError(f'Missing approved FOODEX brand assets: {names}')


def _copy(source: Path, destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, destination)


def _write_android_brand_resources(app: Path) -> None:
    res = app / 'src' / 'main' / 'res'

    # Legacy launcher resources. The high-resolution source is intentionally
    # reused across density folders; Android launchers scale it into their icon
    # slot while adaptive-icon capable devices use the transparent foreground.
    for density in ('mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'):
        folder = res / f'mipmap-{density}'
        _copy(APP_ICON, folder / 'ic_launcher.png')
        _copy(APP_ICON, folder / 'ic_launcher_round.png')

    drawable = res / 'drawable-nodpi'
    _copy(APP_ICON_FOREGROUND, drawable / 'foodex_launcher_foreground.png')
    _copy(APP_ICON_FOREGROUND, drawable / 'foodex_splash_icon.png')
    _copy(SPLASH_IMAGE, drawable / 'foodex_splash_full.png')

    values = res / 'values'
    values.mkdir(parents=True, exist_ok=True)
    (values / 'foodex_brand.xml').write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<resources>\n'
        f'    <color name="foodex_splash_background">{SPLASH_BACKGROUND}</color>\n'
        f'    <color name="foodex_splash_accent">{SPLASH_ACCENT}</color>\n'
        f'    <color name="foodex_icon_background">{SPLASH_BACKGROUND}</color>\n'
        '</resources>\n'
    )

    adaptive = res / 'mipmap-anydpi-v26'
    adaptive.mkdir(parents=True, exist_ok=True)
    adaptive_xml = (
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <background android:drawable="@color/foodex_icon_background" />\n'
        '    <foreground android:drawable="@drawable/foodex_launcher_foreground" />\n'
        '</adaptive-icon>\n'
    )
    (adaptive / 'ic_launcher.xml').write_text(adaptive_xml)
    (adaptive / 'ic_launcher_round.xml').write_text(adaptive_xml)

    launch_background = (
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<layer-list xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <item android:drawable="@color/foodex_splash_background" />\n'
        '    <item>\n'
        '        <bitmap\n'
        '            android:gravity="fill"\n'
        '            android:src="@drawable/foodex_splash_full" />\n'
        '    </item>\n'
        '</layer-list>\n'
    )
    for folder_name in ('drawable', 'drawable-v21'):
        folder = res / folder_name
        folder.mkdir(parents=True, exist_ok=True)
        (folder / 'launch_background.xml').write_text(launch_background)

    _patch_android_launch_theme(res / 'values' / 'styles.xml', android_12=False)
    _patch_android_launch_theme(res / 'values-v31' / 'styles.xml', android_12=True)


def _patch_android_launch_theme(path: Path, *, android_12: bool) -> None:
    if path.exists():
        tree = ET.parse(path)
        root = tree.getroot()
    else:
        path.parent.mkdir(parents=True, exist_ok=True)
        root = ET.Element('resources')
        tree = ET.ElementTree(root)

    launch = next(
        (element for element in root.findall('style') if element.get('name') == 'LaunchTheme'),
        None,
    )
    if launch is None:
        launch = ET.SubElement(
            root,
            'style',
            {
                'name': 'LaunchTheme',
                'parent': '@android:style/Theme.Light.NoTitleBar',
            },
        )

    items = {
        'android:windowBackground': '@drawable/launch_background',
        'android:windowLightStatusBar': 'false',
        'android:statusBarColor': '@color/foodex_splash_background',
        'android:navigationBarColor': '@color/foodex_splash_background',
    }
    if android_12:
        items.update(
            {
                'android:windowSplashScreenBackground': '@color/foodex_splash_background',
                'android:windowSplashScreenAnimatedIcon': '@drawable/foodex_splash_icon',
                'android:windowSplashScreenIconBackgroundColor': '@color/foodex_splash_background',
            }
        )

    existing = {item.get('name'): item for item in launch.findall('item')}
    for name, value in items.items():
        item = existing.get(name)
        if item is None:
            item = ET.SubElement(launch, 'item', {'name': name})
        item.text = value

    ET.indent(tree, space='    ')
    tree.write(path, encoding='utf-8', xml_declaration=True)


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
                'import io.flutter.embedding.android.FlutterActivity',
                'import android.app.NotificationChannel\n'
                'import android.app.NotificationManager\n'
                'import android.os.Build\n'
                'import android.os.Bundle\n'
                'import io.flutter.embedding.android.FlutterActivity',
            )
            text = re.sub(
                r'class MainActivity\s*:\s*FlutterActivity\(\)\s*',
                'class MainActivity : FlutterActivity() {\n'
                '    override fun onCreate(savedInstanceState: Bundle?) {\n'
                '        super.onCreate(savedInstanceState)\n'
                '        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {\n'
                '            val channel = NotificationChannel(\n'
                '                "foodex_updates",\n'
                '                "FOODEX Updates",\n'
                '                NotificationManager.IMPORTANCE_DEFAULT\n'
                '            )\n'
                '            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)\n'
                '        }\n'
                '    }\n'
                '}\n',
                text,
            )
        path.write_text(text)

    _write_android_brand_resources(app)


def _render_ios_app_icons(app_icon_set: Path) -> None:
    contents_path = app_icon_set / 'Contents.json'
    if not contents_path.exists():
        raise RuntimeError('Generated iOS AppIcon Contents.json was not found')

    contents = json.loads(contents_path.read_text())
    sips = shutil.which('sips')
    for image in contents.get('images', []):
        filename = image.get('filename')
        size = image.get('size')
        scale = image.get('scale')
        if not filename or not size or not scale:
            continue
        points = float(size.split('x', 1)[0])
        multiplier = float(scale.rstrip('x'))
        pixels = int(round(points * multiplier))
        destination = app_icon_set / filename
        if sips:
            subprocess.run(
                [sips, '-z', str(pixels), str(pixels), str(APP_ICON), '--out', str(destination)],
                check=True,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
        else:
            # Linux validation jobs do not compile iOS. The macOS iOS job reruns
            # this script and creates exact-size files via sips before xcodebuild.
            _copy(APP_ICON, destination)


def _write_ios_splash(ios: Path) -> None:
    assets = ios / 'Runner' / 'Assets.xcassets'
    splash_set = assets / 'FoodexSplash.imageset'
    splash_set.mkdir(parents=True, exist_ok=True)
    _copy(SPLASH_IMAGE, splash_set / 'FoodexSplash.png')
    (splash_set / 'Contents.json').write_text(
        json.dumps(
            {
                'images': [
                    {
                        'filename': 'FoodexSplash.png',
                        'idiom': 'universal',
                        'scale': '1x',
                    }
                ],
                'info': {'author': 'xcode', 'version': 1},
            },
            indent=2,
        )
        + '\n'
    )

    storyboard = ios / 'Runner' / 'Base.lproj' / 'LaunchScreen.storyboard'
    if not storyboard.exists():
        raise RuntimeError('Generated iOS LaunchScreen.storyboard was not found')

    tree = ET.parse(storyboard)
    root = tree.getroot()
    image_view = next((node for node in root.iter('imageView') if node.get('image')), None)
    if image_view is None:
        raise RuntimeError('Generated iOS LaunchScreen image view was not found')
    image_view.set('image', 'FoodexSplash')
    image_view.set('contentMode', 'scaleAspectFill')
    image_view.set('clipsSubviews', 'YES')

    parent_view = None
    for view in root.iter('view'):
        subviews = view.find('subviews')
        if subviews is not None and image_view in list(subviews):
            parent_view = view
            break
    if parent_view is None:
        raise RuntimeError('Generated iOS LaunchScreen root view was not found')

    view_id = parent_view.get('id')
    image_id = image_view.get('id')
    constraints = parent_view.find('constraints')
    if constraints is None:
        constraints = ET.SubElement(parent_view, 'constraints')
    for constraint in list(constraints):
        if image_id in (constraint.get('firstItem'), constraint.get('secondItem')):
            constraints.remove(constraint)
    edges = (
        ('leading', 'leading', 'Fdx-LD-001'),
        ('trailing', 'trailing', 'Fdx-TR-002'),
        ('top', 'top', 'Fdx-TP-003'),
        ('bottom', 'bottom', 'Fdx-BT-004'),
    )
    for first, second, identifier in edges:
        ET.SubElement(
            constraints,
            'constraint',
            {
                'firstItem': image_id,
                'firstAttribute': first,
                'secondItem': view_id,
                'secondAttribute': second,
                'id': identifier,
            },
        )

    background = next(
        (node for node in parent_view.findall('color') if node.get('key') == 'backgroundColor'),
        None,
    )
    if background is None:
        background = ET.SubElement(parent_view, 'color', {'key': 'backgroundColor'})
    background.attrib.update(
        {
            'red': '0.0',
            'green': '0.1960784314',
            'blue': '0.1372549020',
            'alpha': '1',
            'colorSpace': 'custom',
            'customColorSpace': 'sRGB',
        }
    )

    resources = root.find('resources')
    if resources is None:
        resources = ET.SubElement(root, 'resources')
    for resource in list(resources.findall('image')):
        if resource.get('name') == 'LaunchImage':
            resources.remove(resource)
    if not any(resource.get('name') == 'FoodexSplash' for resource in resources.findall('image')):
        ET.SubElement(
            resources,
            'image',
            {'name': 'FoodexSplash', 'width': '1290', 'height': '2796'},
        )

    ET.indent(tree, space='    ')
    tree.write(storyboard, encoding='utf-8', xml_declaration=True)


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

    _render_ios_app_icons(ios / 'Runner' / 'Assets.xcassets' / 'AppIcon.appiconset')
    _write_ios_splash(ios)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--app', required=True, choices=sorted(IDENTITIES))
    parser.add_argument('--app-dir', required=True, type=Path)
    parser.add_argument('--platform', choices=('all', 'android', 'ios'), default='all')
    args = parser.parse_args()

    _require_brand_assets()
    identity = IDENTITIES[args.app]
    if args.platform in ('all', 'android'):
        patch_android(args.app_dir, identity['bundle_id'])
    if args.platform in ('all', 'ios'):
        patch_ios(args.app_dir, identity['bundle_id'], identity['label'])
    print(f"{args.app}: {identity['bundle_id']} + FOODEX native branding")


if __name__ == '__main__':
    main()
