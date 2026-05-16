package site.fortunetttech.customer.ui.pay

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.customer.data.model.PayResponse
import site.fortunetttech.customer.data.repository.PayRepository
import site.fortunetttech.customer.util.Result
import javax.inject.Inject

@HiltViewModel
class PayViewModel @Inject constructor(private val repo: PayRepository) : ViewModel() {

    sealed class UiState {
        object Idle    : UiState()
        object Loading : UiState()
        data class Success(val message: String) : UiState()
        data class Error(val message: String)   : UiState()
    }

    private val _state = MutableStateFlow<UiState>(UiState.Idle)
    val state: StateFlow<UiState> = _state.asStateFlow()

    fun pay(phone: String) {
        val cleaned = phone.trim().replace(" ", "")
        if (cleaned.length < 9) {
            _state.value = UiState.Error("Enter a valid phone number")
            return
        }
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val r = repo.initiatePayment(cleaned)) {
                is Result.Success -> UiState.Success(r.data.message)
                is Result.Error   -> UiState.Error(r.message)
            }
        }
    }

    fun reset() { _state.value = UiState.Idle }
}
