#!/usr/bin/env python3
"""Apply the approved FOODEX native identity, branding, and push scaffolding.

This script runs after flutter create in CI/release builds. It contains only
public application identity and approved brand assets. Public Android Firebase
client configuration is versioned with each app; service-account keys, signing
material, APNs credentials, and other private secrets stay external.
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
    'van': {
        'bundle_id': 'com.foodex.van',
        'label': 'FOODEX Van',
    },
}

REPO_ROOT = Path(__file__).resolve().parents[1]
BRAND_ROOT = REPO_ROOT / 'assets' / 'mobile_brand'
APP_ICON = BRAND_ROOT / 'app_icon_1024.png'
APP_ICON_FOREGROUND = BRAND_ROOT / 'app_icon_foreground_1024.png'
ANDROID_APP_ICON = APP_ICON
SPLASH_IMAGE = BRAND_ROOT / 'splash_master.png'
SPLASH_BACKGROUND = '#003223'
SPLASH_ACCENT = '#92D853'


def _select_brand_assets(app_name: str) -> None:
    global APP_ICON, APP_ICON_FOREGROUND, ANDROID_APP_ICON, SPLASH_IMAGE
    ANDROID_APP_ICON = APP_ICON
    if app_name == 'customer':
        APP_ICON = BRAND_ROOT / 'customer_app_icon_1024.png'
        APP_ICON_FOREGROUND = APP_ICON
        # Android acceptance build uses the supplied FOODEx Economical Group
        # lockup as the launcher identity. Keep the PNG master for iOS, where
        # the asset catalog requires PNG inputs.
        ANDROID_APP_ICON = (
            REPO_ROOT
            / 'apps'
            / 'customer_app'
            / 'assets'
            / 'branding'
            / 'foodex-economical-group.webp'
        )
        SPLASH_IMAGE = BRAND_ROOT / 'customer_splash.png'
    elif app_name in ('driver', 'van'):
        # Driver and Van intentionally share the approved FOODEX operations
        # identity artwork until a dedicated Van asset pack is approved.
        APP_ICON = BRAND_ROOT / 'customer_app_icon_1024.png'
        APP_ICON_FOREGROUND = APP_ICON
        ANDROID_APP_ICON = (
            REPO_ROOT
            / 'apps'
            / ('driver_app' if app_name == 'driver' else 'customer_app')
            / 'assets'
            / 'branding'
            / 'foodex-economical-group.webp'
        )


GOOGLE_SERVICES_PLUGIN_VERSION = '4.4.4'
ANDROID_DESUGAR_JDK_LIBS_VERSION = '2.1.4'


def _require_brand_assets() -> None:
    missing = [
        path
        for path in (APP_ICON, APP_ICON_FOREGROUND, ANDROID_APP_ICON, SPLASH_IMAGE)
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
    icon_suffix = ANDROID_APP_ICON.suffix.lower()
    for density in ('mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'):
        folder = res / f'mipmap-{density}'
        for stale in (
            folder / 'ic_launcher.png',
            folder / 'ic_launcher.webp',
            folder / 'ic_launcher_round.png',
            folder / 'ic_launcher_round.webp',
        ):
            stale.unlink(missing_ok=True)
        _copy(ANDROID_APP_ICON, folder / f'ic_launcher{icon_suffix}')
        _copy(ANDROID_APP_ICON, folder / f'ic_launcher_round{icon_suffix}')

    drawable = res / 'drawable-nodpi'
    for stale in (
        drawable / 'foodex_launcher_foreground.png',
        drawable / 'foodex_launcher_foreground.webp',
        drawable / 'foodex_splash_icon.png',
        drawable / 'foodex_splash_icon.webp',
    ):
        stale.unlink(missing_ok=True)
    _copy(
        ANDROID_APP_ICON,
        drawable / f'foodex_launcher_foreground{icon_suffix}',
    )
    _copy(
        ANDROID_APP_ICON,
        drawable / f'foodex_splash_icon{icon_suffix}',
    )

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
        '            android:gravity="center"\n'
        '            android:src="@drawable/foodex_splash_icon" />\n'
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


def _configure_android_default_notification_icon(app: Path) -> None:
    manifest = app / 'src' / 'main' / 'AndroidManifest.xml'
    if not manifest.exists():
        raise RuntimeError('Generated Android main manifest was not found')

    text = manifest.read_text()
    marker = 'com.google.firebase.messaging.default_notification_icon'
    if marker in text:
        return

    start = text.find('<application')
    if start < 0:
        raise RuntimeError('Generated Android application manifest node was not found')
    close = text.find('>', start)
    if close < 0:
        raise RuntimeError('Generated Android application manifest tag is malformed')

    metadata = (
        '\n        <meta-data '
        'android:name="com.google.firebase.messaging.default_notification_icon" '
        'android:resource="@mipmap/ic_launcher" />'
    )
    text = text[: close + 1] + metadata + text[close + 1 :]
    manifest.write_text(text)


def _configure_android_firebase(app_dir: Path, bundle_id: str) -> None:
    """Validate the checked-in public Firebase client config and wire Gradle."""
    app = app_dir / 'android' / 'app'
    config_path = app / 'google-services.json'
    if not config_path.is_file():
        raise RuntimeError(f'Missing Firebase Android client config: {config_path}')

    config = json.loads(config_path.read_text())
    packages = {
        client.get('client_info', {}).get('android_client_info', {}).get('package_name')
        for client in config.get('client', [])
    }
    if bundle_id not in packages:
        raise RuntimeError(
            f'Firebase Android client config does not contain package {bundle_id}'
        )

    settings_kts = app_dir / 'android' / 'settings.gradle.kts'
    settings_groovy = app_dir / 'android' / 'settings.gradle'
    if settings_kts.exists():
        text = settings_kts.read_text()
        plugin = (
            f'id("com.google.gms.google-services") version '
            f'"{GOOGLE_SERVICES_PLUGIN_VERSION}" apply false'
        )
        if 'com.google.gms.google-services' not in text:
            text, count = re.subn(
                r'(?m)^plugins\s*\{\s*$',
                'plugins {\n    ' + plugin,
                text,
                count=1,
            )
            if count != 1:
                raise RuntimeError('Generated Android settings.gradle.kts plugins block was not found')
            settings_kts.write_text(text)
    elif settings_groovy.exists():
        text = settings_groovy.read_text()
        plugin = (
            f'id "com.google.gms.google-services" version '
            f'"{GOOGLE_SERVICES_PLUGIN_VERSION}" apply false'
        )
        if 'com.google.gms.google-services' not in text:
            text, count = re.subn(
                r'(?m)^plugins\s*\{\s*$',
                'plugins {\n    ' + plugin,
                text,
                count=1,
            )
            if count != 1:
                raise RuntimeError('Generated Android settings.gradle plugins block was not found')
            settings_groovy.write_text(text)
    else:
        raise RuntimeError('Generated Android settings.gradle(.kts) was not found')

    app_kts = app / 'build.gradle.kts'
    app_groovy = app / 'build.gradle'
    if app_kts.exists():
        text = app_kts.read_text()
        if 'com.google.gms.google-services' not in text:
            text, count = re.subn(
                r'(?m)^plugins\s*\{\s*$',
                'plugins {\n    id("com.google.gms.google-services")',
                text,
                count=1,
            )
            if count != 1:
                raise RuntimeError('Generated Android app build.gradle.kts plugins block was not found')
            app_kts.write_text(text)
    elif app_groovy.exists():
        text = app_groovy.read_text()
        if 'com.google.gms.google-services' not in text:
            text, count = re.subn(
                r'(?m)^plugins\s*\{\s*$',
                'plugins {\n    id "com.google.gms.google-services"',
                text,
                count=1,
            )
            if count != 1:
                raise RuntimeError('Generated Android app build.gradle plugins block was not found')
            app_groovy.write_text(text)
    else:
        raise RuntimeError('Generated Android app build.gradle(.kts) was not found')


def _enable_android_core_library_desugaring(app: Path) -> None:
    app_kts = app / 'build.gradle.kts'
    app_groovy = app / 'build.gradle'

    if app_kts.exists():
        text = app_kts.read_text()
        if 'isCoreLibraryDesugaringEnabled = true' not in text:
            marker = '    compileOptions {\n'
            if marker not in text:
                raise RuntimeError('Generated Android Kotlin compileOptions block was not found')
            text = text.replace(
                marker,
                marker + '        isCoreLibraryDesugaringEnabled = true\n',
                1,
            )
        dependency = (
            'coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:'
            f'{ANDROID_DESUGAR_JDK_LIBS_VERSION}")'
        )
        if 'coreLibraryDesugaring(' not in text:
            marker = 'dependencies {\n'
            if marker in text:
                text = text.replace(marker, marker + '    ' + dependency + '\n', 1)
            else:
                text = text.rstrip() + '\n\ndependencies {\n    ' + dependency + '\n}\n'
        app_kts.write_text(text)
        return

    if app_groovy.exists():
        text = app_groovy.read_text()
        if 'coreLibraryDesugaringEnabled true' not in text:
            marker = '    compileOptions {\n'
            if marker not in text:
                raise RuntimeError('Generated Android Groovy compileOptions block was not found')
            text = text.replace(
                marker,
                marker + '        coreLibraryDesugaringEnabled true\n',
                1,
            )
        dependency = (
            "coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:"
            f"{ANDROID_DESUGAR_JDK_LIBS_VERSION}'"
        )
        if 'coreLibraryDesugaring ' not in text:
            marker = 'dependencies {\n'
            if marker in text:
                text = text.replace(marker, marker + '    ' + dependency + '\n', 1)
            else:
                text = text.rstrip() + '\n\ndependencies {\n    ' + dependency + '\n}\n'
        app_groovy.write_text(text)
        return

    raise RuntimeError('Generated Android app build.gradle(.kts) was not found')


def _configure_android_foreground_location(app: Path, *, background_delivery: bool = False) -> None:
    manifest = app / 'src' / 'main' / 'AndroidManifest.xml'
    if not manifest.exists():
        raise RuntimeError('Generated Android main manifest was not found')

    text = manifest.read_text()
    permissions = [
        'android.permission.ACCESS_COARSE_LOCATION',
        'android.permission.ACCESS_FINE_LOCATION',
    ]
    if background_delivery:
        permissions.extend(
            [
                'android.permission.FOREGROUND_SERVICE',
                'android.permission.FOREGROUND_SERVICE_LOCATION',
            ]
        )
    for permission in permissions:
        if permission in text:
            continue
        marker = '<application'
        if marker not in text:
            raise RuntimeError('Generated Android application manifest node was not found')
        text = text.replace(
            marker,
            f'<uses-permission android:name="{permission}" />\n    {marker}',
            1,
        )
    manifest.write_text(text)


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
        if bundle_id in (
            IDENTITIES['customer']['bundle_id'],
            IDENTITIES['driver']['bundle_id'],
        ):
            text = text.replace(
                'import io.flutter.embedding.android.FlutterActivity',
                'import io.flutter.embedding.android.FlutterFragmentActivity',
            )
            text = text.replace('FlutterActivity()', 'FlutterFragmentActivity()')
        path.write_text(text)

    _enable_android_core_library_desugaring(app)
    _configure_android_firebase(app_dir, bundle_id)
    if bundle_id in (
        IDENTITIES['customer']['bundle_id'],
        IDENTITIES['driver']['bundle_id'],
    ):
        _configure_android_foreground_location(
            app,
            background_delivery=bundle_id == IDENTITIES['driver']['bundle_id'],
        )
    if bundle_id in (
        IDENTITIES['customer']['bundle_id'],
        IDENTITIES['driver']['bundle_id'],
    ):
        manifest = app / 'src' / 'main' / 'AndroidManifest.xml'
        text = manifest.read_text()
        permission = 'android.permission.USE_BIOMETRIC'
        if permission not in text:
            marker = '<application'
            text = text.replace(
                marker,
                f'<uses-permission android:name="{permission}" />\n    {marker}',
                1,
            )
            manifest.write_text(text)
    _write_android_brand_resources(app)
    _configure_android_default_notification_icon(app)


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
            {'name': 'FoodexSplash', 'width': '432', 'height': '936'},
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
    if bundle_id == IDENTITIES['customer']['bundle_id']:
        plist['NSLocationWhenInUseUsageDescription'] = (
            'FOODEX uses your location only when you choose Share my location '
            'to save an accurate delivery address.'
        )
        plist['NSCameraUsageDescription'] = (
            'Scan product barcodes and QR codes for marketplace search.'
        )
        plist['NSFaceIDUsageDescription'] = (
            'FOODEX uses Face ID only when you enable biometric sign-in '
            'to unlock your saved customer session on this device.'
        )
    elif bundle_id == IDENTITIES['driver']['bundle_id']:
        plist['NSLocationWhenInUseUsageDescription'] = (
            'FOODEX Driver requires your precise location while you use the app '
            'and during an active delivery so live driver position can continue '
            'when the app is backgrounded.'
        )
        plist['NSFaceIDUsageDescription'] = (
            'FOODEX Driver uses Face ID only when you enable biometric sign-in '
            'to unlock your saved driver session on this device.'
        )
        background_modes = list(plist.get('UIBackgroundModes', []))
        if 'location' not in background_modes:
            background_modes.append('location')
        plist['UIBackgroundModes'] = background_modes
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

    _select_brand_assets(args.app)
    _require_brand_assets()
    identity = IDENTITIES[args.app]
    if args.platform in ('all', 'android'):
        patch_android(args.app_dir, identity['bundle_id'])
    if args.platform in ('all', 'ios'):
        patch_ios(args.app_dir, identity['bundle_id'], identity['label'])
    print(f"{args.app}: {identity['bundle_id']} + FOODEX native branding")


if __name__ == '__main__':
    main()
