package site.fortunetttech.admin.data.repository

import site.fortunetttech.admin.data.model.RoutersResponse
import site.fortunetttech.admin.data.network.ApiService
import site.fortunetttech.admin.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class RouterRepository @Inject constructor(private val api: ApiService) {

    suspend fun getRouters(): Result<RoutersResponse> = try {
        val r = api.routers()
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to load routers (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
