package site.fortunetttech.admin.data.repository

import site.fortunetttech.admin.data.model.*
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

    suspend fun getClient(id: Int): Result<Client> = try {
        val r = api.client(id)
        if (r.isSuccessful) Result.Success(r.body()!!.data)
        else Result.Error("Client not found (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun createClient(req: CreateClientRequest): Result<CreateClientResponse> = try {
        val r = api.createClient(req)
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to create client (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun updateClient(req: UpdateClientRequest): Result<String> = try {
        val r = api.updateClient(req)
        if (r.isSuccessful) Result.Success(r.body()?.get("message")?.toString() ?: "Updated")
        else Result.Error(r.body()?.get("error")?.toString() ?: "Failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun deleteClient(id: Int): Result<String> = try {
        val r = api.deleteClient(DeleteRequest(id))
        if (r.isSuccessful) Result.Success("Deleted")
        else Result.Error(r.body()?.get("error")?.toString() ?: "Failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
