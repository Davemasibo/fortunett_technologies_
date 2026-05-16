package site.fortunetttech.customer.data.repository

import site.fortunetttech.customer.data.model.PayRequest
import site.fortunetttech.customer.data.model.PayResponse
import site.fortunetttech.customer.data.network.ApiService
import site.fortunetttech.customer.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class PayRepository @Inject constructor(private val api: ApiService) {

    suspend fun initiatePayment(phone: String, packageId: Int? = null): Result<PayResponse> = try {
        val r = api.pay(PayRequest(phone = phone, packageId = packageId))
        if (r.isSuccessful) {
            val body = r.body()!!
            if (body.success) Result.Success(body)
            else Result.Error(body.message)
        } else {
            Result.Error("Payment failed (${r.code()})")
        }
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
