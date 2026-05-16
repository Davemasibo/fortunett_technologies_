package site.fortunetttech.admin.data.repository

import site.fortunetttech.admin.data.model.ClientsResponse
import site.fortunetttech.admin.data.network.ApiService
import site.fortunetttech.admin.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class ClientRepository @Inject constructor(private val api: ApiService) {

    suspend fun getClients(
        page: Int = 1,
        search: String? = null,
        status: String? = null
    ): Result<ClientsResponse> = try {
        val r = api.clients(page = page, search = search, status = status)
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to load clients (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
