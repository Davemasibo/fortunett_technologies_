package site.fortunetttech.admin.ui.packages

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
import site.fortunetttech.admin.data.repository.PackageRepository
import site.fortunetttech.admin.util.Result
import javax.inject.Inject

@HiltViewModel
class PackagesViewModel @Inject constructor(private val repo: PackageRepository) : ViewModel() {

    private val _packages = MutableStateFlow<Result<PackagesResponse>?>(null)
    val packages: StateFlow<Result<PackagesResponse>?> = _packages.asStateFlow()

    private val _actionResult = MutableSharedFlow<String>()
    val actionResult: SharedFlow<String> = _actionResult.asSharedFlow()

    init { load() }

    fun load(search: String? = null) {
        viewModelScope.launch {
            _packages.value = null
            _packages.value = repo.getPackages(search = search)
        }
    }

    fun createPackage(req: CreatePackageRequest) {
        viewModelScope.launch {
            when (val r = repo.createPackage(req)) {
                is Result.Success -> { _actionResult.emit(r.data); load() }
                is Result.Error   -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }

    fun updatePackage(req: UpdatePackageRequest) {
        viewModelScope.launch {
            when (val r = repo.updatePackage(req)) {
                is Result.Success -> { _actionResult.emit(r.data); load() }
                is Result.Error   -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }

    fun deletePackage(id: Int) {
        viewModelScope.launch {
            when (val r = repo.deletePackage(id)) {
                is Result.Success -> { _actionResult.emit("Package deleted"); load() }
                is Result.Error   -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }
}
