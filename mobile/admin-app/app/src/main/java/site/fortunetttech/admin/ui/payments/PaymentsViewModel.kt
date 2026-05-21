package site.fortunetttech.admin.ui.payments

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.admin.data.model.AdminPaymentsResponse
import site.fortunetttech.admin.data.repository.PaymentRepository
import site.fortunetttech.admin.util.Result
import javax.inject.Inject

@HiltViewModel
class PaymentsViewModel @Inject constructor(private val repo: PaymentRepository) : ViewModel() {

    private val _payments = MutableStateFlow<Result<AdminPaymentsResponse>?>(null)
    val payments: StateFlow<Result<AdminPaymentsResponse>?> = _payments.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch { _payments.value = repo.getPayments() }
    }
}
