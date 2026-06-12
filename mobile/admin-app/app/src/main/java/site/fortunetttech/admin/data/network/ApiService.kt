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

    // ── Packages ──────────────────────────────────────────────────────────────

    @GET("api/v1/packages/index.php")
    suspend fun packages(
        @Query("page")     page: Int      = 1,
        @Query("per_page") perPage: Int   = 50,
        @Query("search")   search: String? = null,
        @Query("type")     type: String?   = null
    ): Response<PackagesResponse>

    @POST("api/v1/packages/create.php")
    suspend fun createPackage(@Body request: CreatePackageRequest): Response<Map<String, Any>>

    @POST("api/v1/packages/update.php")
    suspend fun updatePackage(@Body request: UpdatePackageRequest): Response<Map<String, Any>>

    @POST("api/v1/packages/delete.php")
    suspend fun deletePackage(@Body request: DeleteRequest): Response<Map<String, Any>>

    // ── Vouchers ──────────────────────────────────────────────────────────────

    @GET("api/v1/vouchers/index.php")
    suspend fun vouchers(
        @Query("page")     page: Int      = 1,
        @Query("per_page") perPage: Int   = 50,
        @Query("status")   status: String? = null,
        @Query("search")   search: String? = null
    ): Response<VouchersResponse>

    @POST("api/v1/vouchers/generate.php")
    suspend fun generateVouchers(@Body request: GenerateVouchersRequest): Response<GenerateVouchersResponse>

    @POST("api/v1/vouchers/delete.php")
    suspend fun deleteVouchers(@Body request: DeleteVouchersRequest): Response<Map<String, Any>>

    // ── Client CRUD ───────────────────────────────────────────────────────────

    @POST("api/v1/clients/create.php")
    suspend fun createClient(@Body request: CreateClientRequest): Response<CreateClientResponse>

    @POST("api/v1/clients/update.php")
    suspend fun updateClient(@Body request: UpdateClientRequest): Response<Map<String, Any>>

    @POST("api/v1/clients/delete.php")
    suspend fun deleteClient(@Body request: DeleteRequest): Response<Map<String, Any>>

    // ── Payments ──────────────────────────────────────────────────────────────

    @POST("api/v1/payments/record.php")
    suspend fun recordPayment(@Body request: RecordPaymentRequest): Response<RecordPaymentResponse>
}
