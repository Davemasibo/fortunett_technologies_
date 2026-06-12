package site.fortunetttech.admin.ui.clients

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.fortunetttech.admin.data.model.*
import site.fortunetttech.admin.data.repository.ClientRepository
import site.fortunetttech.admin.data.repository.PackageRepository
import site.fortunetttech.admin.util.Result
import javax.inject.Inject

@HiltViewModel
class AddClientViewModel @Inject constructor(
    private val clientRepo: ClientRepository,
    private val pkgRepo: PackageRepository
) : ViewModel() {

    private val _packages = MutableStateFlow<List<Package>>(emptyList())
    val packages: StateFlow<List<Package>> = _packages.asStateFlow()

    private val _result = MutableSharedFlow<Pair<Boolean, String>>()
    val result: SharedFlow<Pair<Boolean, String>> = _result.asSharedFlow()

    private val _loading = MutableStateFlow(false)
    val loading: StateFlow<Boolean> = _loading.asStateFlow()

    init { loadPackages() }

    private fun loadPackages() {
        viewModelScope.launch {
            val r = pkgRepo.getPackages()
            if (r is Result.Success) _packages.value = r.data.data
        }
    }

    fun createClient(req: CreateClientRequest) {
        viewModelScope.launch {
            _loading.value = true
            when (val r = clientRepo.createClient(req)) {
                is Result.Success -> _result.emit(true to r.data.message)
                is Result.Error   -> _result.emit(false to r.message)
            }
            _loading.value = false
        }
    }
}
