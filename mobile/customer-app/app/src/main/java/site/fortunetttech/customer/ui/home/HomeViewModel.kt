package site.fortunetttech.customer.ui.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.customer.data.model.ProfileResponse
import site.fortunetttech.customer.data.repository.AuthRepository
import site.fortunetttech.customer.data.repository.ProfileRepository
import site.fortunetttech.customer.util.Result
import javax.inject.Inject

@HiltViewModel
class HomeViewModel @Inject constructor(
    private val profileRepo: ProfileRepository,
    private val authRepo: AuthRepository
) : ViewModel() {

    private val _profile = MutableStateFlow<Result<ProfileResponse>?>(null)
    val profile: StateFlow<Result<ProfileResponse>?> = _profile.asStateFlow()

    private val _loggedOut = MutableStateFlow(false)
    val loggedOut: StateFlow<Boolean> = _loggedOut.asStateFlow()

    init { load() }

    fun load() {
        viewModelScope.launch { _profile.value = profileRepo.getProfile() }
    }

    fun logout() {
        viewModelScope.launch { authRepo.logout(); _loggedOut.value = true }
    }
}
