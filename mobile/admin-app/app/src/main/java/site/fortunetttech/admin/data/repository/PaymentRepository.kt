package site.fortunetttech.admin.data.repository

import site.fortunetttech.admin.data.model.*
import site.fortunetttech.admin.data.network.ApiService
import site.fortunetttech.admin.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class PaymentRepository @Inject constructor(private val api: ApiService) {

    suspend fun getPayments(page: Int = 1): Result<AdminPaymentsResponse> = try {
        val r = api.payments(page = page)
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to load payments (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun recordPayment(req: RecordPaymentRequest): Result<RecordPaymentResponse> = try {
        val r = api.recordPayment(req)
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to record payment (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
