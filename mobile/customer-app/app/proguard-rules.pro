-keepattributes Signature, Exceptions, RuntimeVisibleAnnotations, RuntimeVisibleParameterAnnotations
-keepclassmembers,allowshrinking,allowobfuscation interface * {
    @retrofit2.http.* <methods>;
}
-dontwarn retrofit2.**

-keep class site.fortunetttech.customer.data.model.** { *; }
-keep class com.google.gson.** { *; }
-dontwarn sun.misc.**

-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn javax.annotation.**
