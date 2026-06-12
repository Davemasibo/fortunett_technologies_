package site.fortunetttech.customer.data.model

import com.google.gson.annotations.SerializedName

// ── Auth ─────────────────────────────────────────────────────────────────────

data class LoginRequest(
    val username: String,
    val password: String,
    @SerializedName("device_name") val deviceName: String = "FortuNett Customer Android"
)

data class LoginResponse(
    @SerializedName("access_token")  val accessToken: String,
    @SerializedName("refresh_token") val refreshToken: String,
    @SerializedName("token_type")    val tokenType: String,
    @SerializedName("expires_in")    val expiresIn: Int,
    val customer: CustomerInfo
)

data class CustomerInfo(val id: Int, val full_name: String, val username: String)

data class RefreshResponse(
    @SerializedName("access_token") val accessToken: String,
    @SerializedName("expires_in")   val expiresIn: Int
)

// ── Profile ───────────────────────────────────────────────────────────────────

data class ProfileResponse(
    val id: Int,
    val full_name: String,
    val username: String,
    val phone: String?,
    val email: String?,
    val account_number: String?,
    val account_balance: Double?,
    val status: String,
    val subscription: Subscription,
    val recent_payments: List<Payment>,
    val isp: IspBranding
)

data class Subscription(
    val package_name: String?,
    val package_price: Double?,
    val expiry_date: String?,
    @SerializedName("expires_in_seconds") val expiresInSeconds: Long?,
    val status: String
)

data class IspBranding(val name: String, val brand_color: String?)

// ── Payments ──────────────────────────────────────────────────────────────────

data class Payment(
    val id: Int,
    val amount: Double,
    val method: String,
    val status: String,
    val created_at: String
)

// ── Available Packages ────────────────────────────────────────────────────────

data class PackagesResponse(val data: List<AvailablePackage>)

data class AvailablePackage(
    val id: Int,
    val name: String,
    val price: Double,
    val description: String?,
    val download_speed: Int,
    val upload_speed: Int,
    val validity_value: Int,
    val validity_unit: String,
    val connection_type: String,
    val device_limit: Int
)

// ── Pay (STK Push) ────────────────────────────────────────────────────────────

data class PayRequest(
    val phone: String,
    @SerializedName("package_id") val packageId: Int? = null
)

data class PayResponse(
    val success: Boolean,
    val message: String,
    @SerializedName("checkout_request_id") val checkoutRequestId: String?
)

// ── Sessions ──────────────────────────────────────────────────────────────────

data class Session(
    val id: Int,
    val ip_address: String,
    val mac_address: String?,
    val user_agent: String?,
    val created_at: String,
    val last_activity: String?
)

data class SessionsResponse(val data: List<Session>)

// ── Payment Status ─────────────────────────────────────────────────────────────

data class PaymentStatusResponse(
    val status: String,
    val amount: Double?,
    val message: String
)

// ── Profile Update ────────────────────────────────────────────────────────────

data class UpdateProfileRequest(
    val full_name: String?,
    val phone: String?,
    val email: String?
)

data class ChangePasswordRequest(
    val current_password: String,
    val new_password: String
)
