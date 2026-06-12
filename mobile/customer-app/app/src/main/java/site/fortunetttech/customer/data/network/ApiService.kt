package site.fortunetttech.customer.data.network

import retrofit2.Call
import retrofit2.Response
import retrofit2.http.*
import site.fortunetttech.customer.data.model.*

interface ApiService {

    @POST("api/v1/customer/auth/login.php")
    suspend fun login(@Body request: LoginRequest): Response<LoginResponse>

    /** Synchronous — called inside OkHttp's Authenticator to avoid coroutine deadlock. */
    @POST("api/v1/auth/refresh.php")
    fun refresh(@Body body: Map<String, String>): Call<RefreshResponse>

    @POST("api/v1/auth/logout.php")
    suspend fun logout(@Body body: Map<String, String>): Response<Unit>

    @GET("api/v1/customer/profile.php")
    suspend fun profile(): Response<ProfileResponse>

    @POST("api/v1/customer/pay.php")
    suspend fun pay(@Body request: PayRequest): Response<PayResponse>

    @GET("api/v1/customer/packages.php")
    suspend fun packages(@Query("type") type: String? = null): Response<PackagesResponse>

    @POST("api/v1/customer/update_profile.php")
    suspend fun updateProfile(@Body request: UpdateProfileRequest): Response<Map<String, Any>>

    @POST("api/v1/customer/change_password.php")
    suspend fun changePassword(@Body request: ChangePasswordRequest): Response<Map<String, Any>>

    @GET("api/v1/customer/payment_status.php")
    suspend fun paymentStatus(
        @Query("checkout_request_id") checkoutRequestId: String
    ): Response<PaymentStatusResponse>

    @GET("api/v1/customer/sessions.php")
    suspend fun sessions(): Response<SessionsResponse>
}
