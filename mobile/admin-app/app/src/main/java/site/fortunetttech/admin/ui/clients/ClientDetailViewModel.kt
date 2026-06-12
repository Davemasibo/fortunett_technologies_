package site.fortunetttech.admin.ui.clients

import androidx.lifecycle.SavedStateHandle
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
import site.fortunetttech.admin.data.repository.PaymentRepository
import site.fortunetttech.admin.util.Result
import javax.inject.Inject

@HiltViewModel
class ClientDetailViewModel @Inject constructor(
    private val clientRepo: ClientRepository,
    private val pkgRepo: PackageRepository,
    private val payRepo: PaymentRepository,
    savedState: SavedStateHandle
) : ViewModel() {

    val clientId: Int = savedState["clientId"] ?: 0

    private val _client = MutableStateFlow<Result<Client>?>(null)
    val client: StateFlow<Result<Client>?> = _client.asStateFlow()

    private val _packages = MutableStateFlow<List<Package>>(emptyList())
    val packages: StateFlow<List<Package>> = _packages.asStateFlow()

    private val _actionResult = MutableSharedFlow<String>()
    val actionResult: SharedFlow<String> = _actionResult.asSharedFlow()

    private val _deleted = MutableSharedFlow<Boolean>()
    val deleted: SharedFlow<Boolean> = _deleted.asSharedFlow()

    init {
        load()
        loadPackages()
    }

    fun load() {
        if (clientId == 0) return
        viewModelScope.launch {
            _client.value = clientRepo.getClient(clientId)
        }
    }

    private fun loadPackages() {
        viewModelScope.launch {
            val r = pkgRepo.getPackages()
            if (r is Result.Success) _packages.value = r.data.data
        }
    }

    fun suspend_() = action("suspend")
    fun activate()  = action("activate")
    fun renew(extendDays: Int = 0) {
        viewModelScope.launch {
            val req = UpdateClientRequest(id = clientId, action = "renew", extend_days = extendDays.takeIf { it > 0 })
            when (val r = clientRepo.updateClient(req)) {
                is Result.Success -> { _actionResult.emit(r.data); load() }
                is Result.Error   -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }

    fun update(req: UpdateClientRequest) {
        viewModelScope.launch {
            when (val r = clientRepo.updateClient(req)) {
                is Result.Success -> { _actionResult.emit(r.data); load() }
                is Result.Error   -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }

    fun delete() {
        viewModelScope.launch {
            when (val r = clientRepo.deleteClient(clientId)) {
                is Result.Success -> _deleted.emit(true)
                is Result.Error   -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }

    fun recordPayment(req: RecordPaymentRequest) {
        viewModelScope.launch {
            when (val r = payRepo.recordPayment(req)) {
                is Result.Success -> _actionResult.emit(r.data.message)
                is Result.Error   -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }

    private fun action(type: String) {
        viewModelScope.launch {
            val req = UpdateClientRequest(id = clientId, action = type)
            when (val r = clientRepo.updateClient(req)) {
                is Result.Success -> { _actionResult.emit(r.data); load() }
                is Result.Error   -> _actionResult.emit("Error: ${r.message}")
            }
        }
    }
}
