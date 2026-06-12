package site.fortunetttech.admin.data.repository

import site.fortunetttech.admin.data.model.*
import site.fortunetttech.admin.data.network.ApiService
import site.fortunetttech.admin.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class VoucherRepository @Inject constructor(private val api: ApiService) {

    suspend fun getVouchers(page: Int = 1, status: String? = null, search: String? = null): Result<VouchersResponse> = try {
        val r = api.vouchers(page = page, status = status, search = search)
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to load vouchers (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun generateVouchers(req: GenerateVouchersRequest): Result<GenerateVouchersResponse> = try {
        val r = api.generateVouchers(req)
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Generate failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun deleteVouchers(ids: List<Int>): Result<String> = try {
        val r = api.deleteVouchers(DeleteVouchersRequest(ids))
        if (r.isSuccessful) Result.Success("Deleted")
        else Result.Error(r.body()?.get("error")?.toString() ?: "Failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
