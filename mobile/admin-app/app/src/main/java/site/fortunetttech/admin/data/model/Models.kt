package site.fortunetttech.admin.data.model

import com.google.gson.annotations.SerializedName

// ── Auth ─────────────────────────────────────────────────────────────────────

data class LoginRequest(
    val email: String,
    val password: String,
    @SerializedName("device_name") val deviceName: String = "FortuNett Admin Android"
)

data class LoginResponse(
    @SerializedName("access_token")  val accessToken: String,
    @SerializedName("refresh_token") val refreshToken: String,
    @SerializedName("token_type")    val tokenType: String,
    @SerializedName("expires_in")    val expiresIn: Int,
    val user: UserInfo
)

data class UserInfo(val id: Int, val name: String, val email: String, val role: String)

data class RefreshResponse(
    @SerializedName("access_token") val accessToken: String,
    @SerializedName("token_type")   val tokenType: String,
    @SerializedName("expires_in")   val expiresIn: Int
)

data class MeResponse(
    val id: Int, val name: String, val email: String, val role: String,
    val tenant: TenantInfo
)

data class TenantInfo(val id: Int, val name: String, val subdomain: String, val brand_color: String?)

// ── Clients ──────────────────────────────────────────────────────────────────

data class ClientsResponse(val data: List<Client>, val pagination: Pagination)
data class ClientResponse(val data: Client)

data class Client(
    val id: Int,
    val full_name: String,
    val username: String,
    val phone: String?,
    val email: String?,
    val account_number: String?,
    val status: String,
    val service_type: String,
    val package_name: String?,
    val expiry_date: String?,
    val created_at: String
)

data class Pagination(val total: Int, val page: Int, val per_page: Int, val last_page: Int)

// ── Dashboard ─────────────────────────────────────────────────────────────────

data class DashboardStats(
    val clients: ClientCounts,
    val revenue: RevenueSummary,
    val routers: RouterSummary,
    val recent_payments: List<RecentPayment>
)

data class ClientCounts(val total: Int, val active: Int, val inactive: Int, val expired: Int)
data class RevenueSummary(val this_month: Double, val trend: List<MonthRevenue>)
data class MonthRevenue(val month: String, val total: Double)
data class RouterSummary(val total: Int, val online: Int)

data class RecentPayment(
    val id: Int,
    val client_name: String,
    val amount: Double,
    val method: String,
    val status: String,
    val created_at: String
)

// ── Payments (admin) ─────────────────────────────────────────────────────────

data class AdminPaymentsResponse(
    val data: List<AdminPayment>,
    val total: Int,
    val page: Int,
    @SerializedName("per_page")    val perPage: Int,
    @SerializedName("total_pages") val totalPages: Int,
    @SerializedName("total_amount") val totalAmount: Double
)

data class AdminPayment(
    val id: Int,
    val amount: Double,
    @SerializedName("payment_method") val paymentMethod: String,
    @SerializedName("transaction_id") val transactionId: String?,
    val status: String,
    @SerializedName("payment_date")   val paymentDate: String,
    @SerializedName("client_name")    val clientName: String?,
    @SerializedName("client_phone")   val clientPhone: String?,
    @SerializedName("account_number") val accountNumber: String?
)

// ── Routers ───────────────────────────────────────────────────────────────────

data class RoutersResponse(val data: List<Router>)

data class Router(
    val id: Int,
    val name: String,
    val host: String,
    val status: String,
    val last_seen: String?
)

// ── Packages ──────────────────────────────────────────────────────────────────

data class PackagesResponse(val data: List<Package>, val pagination: Pagination)

data class Package(
    val id: Int,
    val name: String,
    val price: Double,
    val description: String?,
    val download_speed: Int,
    val upload_speed: Int,
    val data_limit: Long,
    val connection_type: String,
    val mikrotik_profile: String?,
    val validity_value: Int,
    val validity_unit: String,
    val device_limit: Int,
    val hotspot_server: String?,
    val rate_limit: String?,
    val status: String
)

data class CreatePackageRequest(
    val name: String,
    val price: Double,
    val connection_type: String,
    val download_speed: Int,
    val upload_speed: Int,
    val validity_value: Int,
    val validity_unit: String,
    val description: String? = null,
    val mikrotik_profile: String? = null,
    val device_limit: Int = 1,
    val hotspot_server: String? = null
)

data class UpdatePackageRequest(
    val id: Int,
    val name: String,
    val price: Double,
    val connection_type: String,
    val download_speed: Int,
    val upload_speed: Int,
    val validity_value: Int,
    val validity_unit: String,
    val status: String,
    val description: String? = null,
    val device_limit: Int = 1
)

data class DeleteRequest(val id: Int)

// ── Vouchers ──────────────────────────────────────────────────────────────────

data class VouchersResponse(
    val data: List<Voucher>,
    val stats: VoucherStats,
    val pagination: Pagination
)

data class Voucher(
    val id: Int,
    val voucher_code: String,
    val status: String,
    val price: Double,
    val expiry_date: String?,
    val created_at: String,
    val package_name: String?,
    val connection_type: String?,
    val used_by_name: String?,
    val used_by_phone: String?
)

data class VoucherStats(
    val total: Int,
    val available: Int,
    val used: Int,
    val expired: Int
)

data class GenerateVouchersRequest(
    val count: Int,
    val package_id: Int?,
    val duration_value: Int,
    val duration_unit: String,
    val price: Double,
    val connection_type: String,
    val prefix: String? = null,
    val expires_at: String? = null
)

data class GenerateVouchersResponse(
    val success: Boolean,
    val count: Int,
    val codes: List<String>,
    val router_synced: Boolean,
    val router_error: String?
)

data class DeleteVouchersRequest(val ids: List<Int>)

// ── Client create / update ────────────────────────────────────────────────────

data class CreateClientRequest(
    val full_name: String,
    val phone: String,
    val email: String?,
    val package_id: Int,
    val connection_type: String,
    val address: String? = null,
    val username: String? = null,
    val password: String? = null,
    val expiry_date: String? = null
)

data class CreateClientResponse(
    val success: Boolean,
    val id: Int?,
    val username: String?,
    val password: String?,
    val mikrotik_synced: Boolean,
    val message: String
)

data class UpdateClientRequest(
    val id: Int,
    val action: String? = null,
    val full_name: String? = null,
    val phone: String? = null,
    val email: String? = null,
    val address: String? = null,
    val status: String? = null,
    val package_id: Int? = null,
    val expiry_date: String? = null,
    val extend_days: Int? = null
)

// ── Record Payment ────────────────────────────────────────────────────────────

data class RecordPaymentRequest(
    val client_id: Int,
    val amount: Double,
    val method: String,
    val reference_code: String? = null,
    val phone: String? = null,
    val notes: String? = null
)

data class RecordPaymentResponse(
    val success: Boolean,
    val message: String,
    val reference: String?,
    val checkout_request_id: String?
)
