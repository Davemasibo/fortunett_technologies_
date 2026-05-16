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

// ── Routers ───────────────────────────────────────────────────────────────────

data class RoutersResponse(val data: List<Router>)

data class Router(
    val id: Int,
    val name: String,
    val host: String,
    val status: String,
    val last_seen: String?
)
