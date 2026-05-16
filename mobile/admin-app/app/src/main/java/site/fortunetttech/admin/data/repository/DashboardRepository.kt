package site.fortunetttech.admin.data.repository

import site.fortunetttech.admin.data.model.DashboardStats
import site.fortunetttech.admin.data.network.ApiService
import site.fortunetttech.admin.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class DashboardRepository @Inject constructor(private val api: ApiService) {

    suspend fun getStats(): Result<DashboardStats> = try {
        val r = api.dashboardStats()
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to load dashboard (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
