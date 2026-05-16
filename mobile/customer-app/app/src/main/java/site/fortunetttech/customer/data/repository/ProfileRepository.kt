package site.fortunetttech.customer.data.repository

import site.fortunetttech.customer.data.model.ProfileResponse
import site.fortunetttech.customer.data.network.ApiService
import site.fortunetttech.customer.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class ProfileRepository @Inject constructor(private val api: ApiService) {

    suspend fun getProfile(): Result<ProfileResponse> = try {
        val r = api.profile()
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to load profile (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
