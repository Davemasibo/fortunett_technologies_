package site.fortunetttech.admin.ui.vouchers

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
import site.fortunetttech.admin.data.repository.VoucherRepository
import site.fortunetttech.admin.util.Result
import javax.inject.Inject

@HiltViewModel
class VouchersViewModel @Inject constructor(
    private val repo: VoucherRepository,
    private val pkgRepo: PackageRepository
) : ViewModel() {

    private val _vouchers = MutableStateFlow<Result<VouchersResponse>?>(null)
    val vouchers: StateFlow<Result<VouchersResponse>?> = _vouchers.asStateFlow()

    private val _packages = MutableStateFlow<List<Package>>(emptyList())
    val packages: StateFlow<List<Package>> = _packages.asStateFlow()

    private val _actionResult = MutableSharedFlow<String>()
    val actionResult: SharedFlow<String> = _actionResult.asSharedFlow()

    init { load(); loadPackages() }

    fun load(status: String? = null) {
        viewModelScope.launch {
            _vouchers.value = null
            _vouchers.value = repo.getVouchers(status = status)
        }
    }

    private fun loadPackages() {
        viewModelScope.launch {
            val r = pkgRepo.getPackages()
            if (r is Result.Success) _packages.value = r.data.data
        }
    }

    fun generate(req: GenerateVouchersRequest) {
        viewModelScope.launch {
            when (val r = repo.generateVouchers(req)) {
                is Result.Success -> {
                    _actionResult.emit("Generated ${r.data.count} voucher${if (r.data.count != 1) "s" else ""}")
                    load()
                }
                is Result.Error -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }

    fun delete(ids: List<Int>) {
        viewModelScope.launch {
            when (val r = repo.deleteVouchers(ids)) {
                is Result.Success -> { _actionResult.emit("Deleted"); load() }
                is Result.Error   -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }
}
