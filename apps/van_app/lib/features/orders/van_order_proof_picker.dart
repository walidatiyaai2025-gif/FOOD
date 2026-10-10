import 'package:image_picker/image_picker.dart';

import 'van_order_contract.dart';

abstract interface class VanOrderProofPicker {
  Future<VanProofAttachment?> pickCamera();
  Future<VanProofAttachment?> pickGallery();
}

class ImagePickerVanOrderProofPicker implements VanOrderProofPicker {
  ImagePickerVanOrderProofPicker({ImagePicker? picker})
      : _picker = picker ?? ImagePicker();

  final ImagePicker _picker;

  @override
  Future<VanProofAttachment?> pickCamera() => _pick(ImageSource.camera);

  @override
  Future<VanProofAttachment?> pickGallery() => _pick(ImageSource.gallery);

  Future<VanProofAttachment?> _pick(ImageSource source) async {
    final image = await _picker.pickImage(
      source: source,
      imageQuality: 82,
    );
    if (image == null) return null;

    return VanProofAttachment(
      path: image.path,
      fileName: image.name,
      byteLength: await image.length(),
      mimeType: image.mimeType,
    );
  }
}
