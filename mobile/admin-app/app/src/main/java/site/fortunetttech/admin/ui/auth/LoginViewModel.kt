package site.fortunetttech.admin.ui.auth

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.admin.data.repository.AuthRepository
import site.fortunetttech.admin.util.Result
import javax.inject.Inject

@HiltViewModel
class LoginViewModel @Inject constructor(private val repo: AuthRepository) : ViewModel() {

    sealed class UiState {
        object Idle    : UiState()
        object Loading : UiState()
        data class Success(val userName: String) : UiState()
        data class Error(val message: String)    : UiState()
    }

    private val _state = MutableStateFlow<UiState>(UiState.Idle)
    val state: StateFlow<UiState> = _state.asStateFlow()

    fun login(subdomain: String, email: String, password: String) {
        if (subdomain.isBlank() || email.isBlank() || password.isBlank()) {
            _state.value = UiState.Error("All fields are required")
            return
        }
        viewModelScope.launch {
            _state.value = UiState.Loading
            _state.value = when (val r = repo.login(subdomain.trim(), email.trim(), password)) {
                is Result.Success -> UiState.Success(r.data.user.name)
                is Result.Error   -> UiState.Error(r.message)
            }
        }
    }
}
