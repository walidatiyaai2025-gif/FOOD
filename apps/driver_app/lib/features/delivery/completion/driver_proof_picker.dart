import 'package:image_picker/image_picker.dart';

import 'driver_completion_contract.dart';

abstract interface class DriverProofPicker {
  Future<DriverProofAttachment?> pickCamera();
  Future<DriverProofAttachment?> pickGallery();
}

class ImagePickerDriverProofPicker implements DriverProofPicker {
  ImagePickerDriverProofPicker({ImagePicker? picker})
      : _picker = picker ?? ImagePicker();

  final ImagePicker _picker;

  @override
  Future<DriverProofAttachment?> pickCamera() =>
      _pick(ImageSource.camera);

  @override
  Future<DriverProofAttachment?> pickGallery() =>
      _pick(ImageSource.gallery);

  Future<DriverProofAttachment?> _pick(ImageSource source) async {
    // Limit camera/gallery images before they reach the 5 MB server upload
    // cap. The proof remains a real photo, with sufficient delivery detail.
    final image = await _picker.pickImage(
      source: source,
      imageQuality: 75,
      maxWidth: 1920,
      maxHeight: 1920,
      requestFullMetadata: false,
    );
    if (image == null) return null;

    return DriverProofAttachment(
      path: image.path,
      fileName: image.name,
      byteLength: await image.length(),
      mimeType: image.mimeType,
    );
  }
}
