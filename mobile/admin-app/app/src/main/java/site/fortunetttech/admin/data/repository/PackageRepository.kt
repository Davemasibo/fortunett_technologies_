package site.fortunetttech.admin.data.repository

import site.fortunetttech.admin.data.model.*
import site.fortunetttech.admin.data.network.ApiService
import site.fortunetttech.admin.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class PackageRepository @Inject constructor(private val api: ApiService) {

    suspend fun getPackages(page: Int = 1, search: String? = null, type: String? = null): Result<PackagesResponse> = try {
        val r = api.packages(page = page, search = search, type = type)
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to load packages (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun createPackage(req: CreatePackageRequest): Result<String> = try {
        val r = api.createPackage(req)
        if (r.isSuccessful) Result.Success(r.body()?.get("message")?.toString() ?: "Created")
        else Result.Error(r.body()?.get("error")?.toString() ?: "Failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun updatePackage(req: UpdatePackageRequest): Result<String> = try {
        val r = api.updatePackage(req)
        if (r.isSuccessful) Result.Success(r.body()?.get("message")?.toString() ?: "Updated")
        else Result.Error(r.body()?.get("error")?.toString() ?: "Failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun deletePackage(id: Int): Result<String> = try {
        val r = api.deletePackage(DeleteRequest(id))
        if (r.isSuccessful) Result.Success("Deleted")
        else Result.Error(r.body()?.get("error")?.toString() ?: "Failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
