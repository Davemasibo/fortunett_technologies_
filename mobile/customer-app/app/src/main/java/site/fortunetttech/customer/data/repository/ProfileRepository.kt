package site.fortunetttech.customer.data.repository

import site.fortunetttech.customer.data.model.*
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

    suspend fun updateProfile(req: UpdateProfileRequest): Result<String> = try {
        val r = api.updateProfile(req)
        if (r.isSuccessful) Result.Success(r.body()?.get("message")?.toString() ?: "Updated")
        else Result.Error(r.body()?.get("error")?.toString() ?: "Failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun changePassword(req: ChangePasswordRequest): Result<String> = try {
        val r = api.changePassword(req)
        if (r.isSuccessful) Result.Success(r.body()?.get("message")?.toString() ?: "Password changed")
        else Result.Error(r.body()?.get("error")?.toString() ?: "Failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun getSessions(): Result<List<Session>> = try {
        val r = api.sessions()
        if (r.isSuccessful) Result.Success(r.body()!!.data)
        else Result.Error("Failed to load sessions (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
