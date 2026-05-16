package site.fortunetttech.admin.data.preferences

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import dagger.hilt.android.qualifiers.ApplicationContext
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Secure token storage using EncryptedSharedPreferences (Android Keystore backed).
 * Stores access token, refresh token, and the ISP subdomain the admin last logged into.
 */
@Singleton
class TokenPreferences @Inject constructor(@ApplicationContext context: Context) {

    private val prefs = EncryptedSharedPreferences.create(
        context,
        "fortunett_admin_tokens",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )

    var accessToken: String?
        get()      = prefs.getString(KEY_ACCESS, null)
        set(value) = prefs.edit().putString(KEY_ACCESS, value).apply()

    var refreshToken: String?
        get()      = prefs.getString(KEY_REFRESH, null)
        set(value) = prefs.edit().putString(KEY_REFRESH, value).apply()

    /** The ISP subdomain — e.g. "acme" from acme.fortunetttech.site */
    var subdomain: String?
        get()      = prefs.getString(KEY_SUBDOMAIN, null)
        set(value) = prefs.edit().putString(KEY_SUBDOMAIN, value).apply()

    var userName: String?
        get()      = prefs.getString(KEY_USER_NAME, null)
        set(value) = prefs.edit().putString(KEY_USER_NAME, value).apply()

    var userRole: String?
        get()      = prefs.getString(KEY_USER_ROLE, null)
        set(value) = prefs.edit().putString(KEY_USER_ROLE, value).apply()

    val isLoggedIn: Boolean
        get() = !refreshToken.isNullOrBlank() && !subdomain.isNullOrBlank()

    fun clear() = prefs.edit().clear().apply()

    companion object {
        private const val KEY_ACCESS    = "access_token"
        private const val KEY_REFRESH   = "refresh_token"
        private const val KEY_SUBDOMAIN = "subdomain"
        private const val KEY_USER_NAME = "user_name"
        private const val KEY_USER_ROLE = "user_role"
    }
}
