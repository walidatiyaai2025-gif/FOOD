class DriverMobileRuntime {
  const DriverMobileRuntime({required this.environment,required this.maintenanceMode,required this.forceUpdate,this.minimumSupportedVersion,this.recommendedVersion,this.maintenanceMessage,this.releaseNotes});
  final String environment; final bool maintenanceMode; final bool forceUpdate; final String? minimumSupportedVersion; final String? recommendedVersion; final String? maintenanceMessage; final String? releaseNotes;
  static Uri endpoint(Uri apiBase,{required String environment,String locale='ar'})=>apiBase.resolve('mobile/runtime?app=driver&environment=$environment&locale=$locale');
  factory DriverMobileRuntime.fromJson(Map<String,dynamic> json){final d=Map<String,dynamic>.from(json['data'] as Map);return DriverMobileRuntime(environment:d['environment'] as String,maintenanceMode:d['maintenance_mode']==true,forceUpdate:d['force_update']==true,minimumSupportedVersion:d['minimum_supported_version'] as String?,recommendedVersion:d['recommended_version'] as String?,maintenanceMessage:d['maintenance_message'] as String?,releaseNotes:d['release_notes'] as String?);}
}
