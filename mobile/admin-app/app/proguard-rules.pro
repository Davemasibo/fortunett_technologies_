# Retrofit — keep annotations and interface methods
-keepattributes Signature, Exceptions, RuntimeVisibleAnnotations, RuntimeVisibleParameterAnnotations
-keepclassmembers,allowshrinking,allowobfuscation interface * {
    @retrofit2.http.* <methods>;
}
-dontwarn retrofit2.**

# Gson — keep model classes so serialization works
-keep class site.fortunetttech.admin.data.model.** { *; }
-keep class com.google.gson.** { *; }
-dontwarn sun.misc.**

# OkHttp + Okio
-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn javax.annotation.**
