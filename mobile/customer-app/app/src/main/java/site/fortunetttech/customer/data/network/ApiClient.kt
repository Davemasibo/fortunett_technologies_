package site.fortunetttech.customer.data.network

import okhttp3.*
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import site.fortunetttech.customer.data.preferences.TokenPreferences
import java.util.concurrent.TimeUnit

// 10.0.2.2 = host machine localhost from Android emulator
private const val BASE_URL = "http://10.0.2.2/"

class SubdomainInterceptor(private val prefs: TokenPreferences) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val subdomain = prefs.subdomain ?: return chain.proceed(chain.request())
        val newUrl = chain.request().url.newBuilder()
            .scheme("http")
            .host("10.0.2.2")
            .build()
        return chain.proceed(chain.request().newBuilder().url(newUrl).build())
    }
}

class AuthInterceptor(private val prefs: TokenPreferences) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val token = prefs.accessToken ?: return chain.proceed(chain.request())
        return chain.proceed(
            chain.request().newBuilder()
                .header("Authorization", "Bearer $token")
                .build()
        )
    }
}

class TokenAuthenticator(
    private val prefs: TokenPreferences,
    private val refreshService: ApiService
) : Authenticator {

    override fun authenticate(route: Route?, response: Response): Request? {
        if (response.request.url.encodedPath.contains("/auth/refresh")) return null
        if (response.priorResponse?.code == 401) { prefs.clear(); return null }

        val refreshToken = prefs.refreshToken ?: return null

        synchronized(this) {
            val latestToken = prefs.accessToken
            val usedToken   = response.request.header("Authorization")?.removePrefix("Bearer ")
            if (latestToken != null && latestToken != usedToken) {
                return response.request.newBuilder()
                    .header("Authorization", "Bearer $latestToken")
                    .build()
            }
            return try {
                val result = refreshService.refresh(mapOf("refresh_token" to refreshToken)).execute()
                if (result.isSuccessful) {
                    prefs.accessToken = result.body()!!.accessToken
                    response.request.newBuilder()
                        .header("Authorization", "Bearer ${prefs.accessToken}")
                        .build()
                } else {
                    prefs.clear(); null
                }
            } catch (_: Exception) { null }
        }
    }
}

fun buildApiService(prefs: TokenPreferences): ApiService {
    val subdomainInterceptor = SubdomainInterceptor(prefs)

    val refreshService: ApiService = Retrofit.Builder()
        .baseUrl(BASE_URL)
        .client(
            OkHttpClient.Builder()
                .addInterceptor(subdomainInterceptor)
                .connectTimeout(30, TimeUnit.SECONDS)
                .readTimeout(30, TimeUnit.SECONDS)
                .build()
        )
        .addConverterFactory(GsonConverterFactory.create())
        .build()
        .create(ApiService::class.java)

    val mainClient = OkHttpClient.Builder()
        .addInterceptor(AuthInterceptor(prefs))
        .addInterceptor(subdomainInterceptor)
        .authenticator(TokenAuthenticator(prefs, refreshService))
        .addInterceptor(HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BASIC })
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .build()

    return Retrofit.Builder()
        .baseUrl(BASE_URL)
        .client(mainClient)
        .addConverterFactory(GsonConverterFactory.create())
        .build()
        .create(ApiService::class.java)
}
