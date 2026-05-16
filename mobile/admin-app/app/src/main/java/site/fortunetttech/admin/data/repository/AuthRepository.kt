package site.fortunetttech.admin.data.repository

import com.google.gson.JsonParser
import site.fortunetttech.admin.data.model.LoginRequest
import site.fortunetttech.admin.data.model.LoginResponse
import site.fortunetttech.admin.data.network.ApiService
import site.fortunetttech.admin.data.preferences.TokenPreferences
import site.fortunetttech.admin.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class AuthRepository @Inject constructor(
    private val api: ApiService,
    private val prefs: TokenPreferences
) {
    suspend fun login(subdomain: String, email: String, password: String): Result<LoginResponse> {
        return try {
            // Set subdomain before the call so SubdomainInterceptor rewrites the host correctly.
            prefs.subdomain = subdomain
            val response = api.login(LoginRequest(email, password))
            if (response.isSuccessful) {
                val body = response.body()!!
                prefs.accessToken  = body.accessToken
                prefs.refreshToken = body.refreshToken
                prefs.userName     = body.user.name
                prefs.userRole     = body.user.role
                Result.Success(body)
            } else {
                prefs.subdomain = null
                Result.Error(parseError(response.errorBody()?.string()))
            }
        } catch (e: Exception) {
            prefs.subdomain = null
            Result.Error(e.message ?: "Network error")
        }
    }

    suspend fun logout() {
        val token = prefs.refreshToken
        if (token != null) {
            runCatching { api.logout(mapOf("refresh_token" to token)) }
        }
        prefs.clear()
    }

    private fun parseError(body: String?): String {
        body ?: return "Unknown error"
        return runCatching {
            val obj = JsonParser.parseString(body).asJsonObject
            obj["error"]?.asString ?: obj["message"]?.asString ?: "Unknown error"
        }.getOrDefault("Unknown error")
    }
}
