package site.fortunetttech.customer.data.repository

import site.fortunetttech.customer.data.model.*
import site.fortunetttech.customer.data.network.ApiService
import site.fortunetttech.customer.util.Result
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class PackagesRepository @Inject constructor(private val api: ApiService) {

    suspend fun getPackages(type: String? = null): Result<PackagesResponse> = try {
        val r = api.packages(type = type)
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Failed to load packages (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }

    suspend fun checkPaymentStatus(checkoutId: String): Result<PaymentStatusResponse> = try {
        val r = api.paymentStatus(checkoutId)
        if (r.isSuccessful) Result.Success(r.body()!!)
        else Result.Error("Status check failed (${r.code()})")
    } catch (e: Exception) {
        Result.Error(e.message ?: "Network error")
    }
}
