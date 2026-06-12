package site.fortunetttech.customer.ui.pay

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.customer.data.model.AvailablePackage
import site.fortunetttech.customer.data.repository.PackagesRepository
import site.fortunetttech.customer.data.repository.PayRepository
import site.fortunetttech.customer.util.Result
import javax.inject.Inject

@HiltViewModel
class PayViewModel @Inject constructor(
    private val repo: PayRepository,
    private val pkgRepo: PackagesRepository
) : ViewModel() {

    sealed class UiState {
        object Idle            : UiState()
        object Loading         : UiState()
        object Polling         : UiState()
        data class Success(val message: String) : UiState()
        data class Error(val message: String)   : UiState()
    }

    private val _state = MutableStateFlow<UiState>(UiState.Idle)
    val state: StateFlow<UiState> = _state.asStateFlow()

    private val _packages = MutableStateFlow<List<AvailablePackage>>(emptyList())
    val packages: StateFlow<List<AvailablePackage>> = _packages.asStateFlow()

    private var pollJob: Job? = null

    init { loadPackages() }

    private fun loadPackages() {
        viewModelScope.launch {
            val r = pkgRepo.getPackages()
            if (r is Result.Success) _packages.value = r.data.data
        }
    }

    fun pay(phone: String, packageId: Int? = null) {
        val cleaned = phone.trim().replace(" ", "")
        if (cleaned.length < 9) {
            _state.value = UiState.Error("Enter a valid phone number")
            return
        }
        viewModelScope.launch {
            _state.value = UiState.Loading
            when (val r = repo.initiatePayment(cleaned, packageId)) {
                is Result.Success -> {
                    val checkoutId = r.data.checkoutRequestId
                    if (checkoutId != null) {
                        _state.value = UiState.Polling
                        startPolling(checkoutId)
                    } else {
                        _state.value = UiState.Success(r.data.message)
                    }
                }
                is Result.Error -> _state.value = UiState.Error(r.message)
            }
        }
    }

    private fun startPolling(checkoutId: String) {
        pollJob?.cancel()
        pollJob = viewModelScope.launch {
            repeat(12) { attempt ->
                delay(5_000)
                when (val r = pkgRepo.checkPaymentStatus(checkoutId)) {
                    is Result.Success -> {
                        val status = r.data.status
                        when {
                            status == "completed" -> {
                                _state.value = UiState.Success("Payment confirmed! ${r.data.message}")
                                return@launch
                            }
                            status == "failed" || status == "cancelled" -> {
                                _state.value = UiState.Error(r.data.message)
                                return@launch
                            }
                        }
                    }
                    is Result.Error -> { /* keep polling */ }
                }
            }
            // After ~60s, give up polling but treat as pending
            _state.value = UiState.Success("STK push sent. Payment may take a moment to confirm.")
        }
    }

    fun reset() {
        pollJob?.cancel()
        _state.value = UiState.Idle
    }

    override fun onCleared() { super.onCleared(); pollJob?.cancel() }
}
