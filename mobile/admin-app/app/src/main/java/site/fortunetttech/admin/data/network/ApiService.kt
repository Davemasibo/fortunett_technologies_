package site.fortunetttech.admin.data.network

import retrofit2.Call
import retrofit2.Response
import retrofit2.http.*
import site.fortunetttech.admin.data.model.*

interface ApiService {

    @POST("api/v1/auth/login.php")
    suspend fun login(@Body request: LoginRequest): Response<LoginResponse>

    /** Synchronous — used by TokenAuthenticator inside OkHttp's Authenticator callback. */
    @POST("api/v1/auth/refresh.php")
    fun refresh(@Body body: Map<String, String>): Call<RefreshResponse>

    @POST("api/v1/auth/logout.php")
    suspend fun logout(@Body body: Map<String, String>): Response<Unit>

    @GET("api/v1/auth/me.php")
    suspend fun me(): Response<MeResponse>

    @GET("api/v1/clients/index.php")
    suspend fun clients(
        @Query("page")     page: Int      = 1,
        @Query("per_page") perPage: Int   = 25,
        @Query("search")   search: String? = null,
        @Query("status")   status: String? = null
    ): Response<ClientsResponse>

    @GET("api/v1/clients/index.php")
    suspend fun client(@Query("id") id: Int): Response<ClientResponse>

    @GET("api/v1/dashboard/stats.php")
    suspend fun dashboardStats(): Response<DashboardStats>

    @GET("api/v1/payments/index.php")
    suspend fun payments(
        @Query("page")     page: Int      = 1,
        @Query("per_page") perPage: Int   = 25,
        @Query("status")   status: String? = null
    ): Response<AdminPaymentsResponse>

    @GET("api/v1/routers/index.php")
    suspend fun routers(): Response<RoutersResponse>
}
