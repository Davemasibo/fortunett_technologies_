package site.fortunetttech.admin.ui.clients

import android.app.AlertDialog
import android.os.Bundle
import android.view.*
import android.widget.*
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import com.google.android.material.snackbar.Snackbar
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.admin.data.model.*
import site.fortunetttech.admin.databinding.FragmentClientDetailBinding
import site.fortunetttech.admin.util.Result

@AndroidEntryPoint
class ClientDetailFragment : Fragment() {

    private var _binding: FragmentClientDetailBinding? = null
    private val binding get() = _binding!!
    private val vm: ClientDetailViewModel by viewModels()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentClientDetailBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.swipeRefresh.setOnRefreshListener { vm.load() }

        lifecycleScope.launch {
            vm.client.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> renderClient(result.data)
                    is Result.Error   -> {
                        binding.tvError.text = result.message
                        binding.tvError.visibility = View.VISIBLE
                    }
                }
            }
        }

        lifecycleScope.launch {
            vm.actionResult.collectLatest { msg ->
                Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
            }
        }

        lifecycleScope.launch {
            vm.deleted.collectLatest {
                if (it) findNavController().popBackStack()
            }
        }

        binding.btnSuspend.setOnClickListener  { vm.suspend_() }
        binding.btnActivate.setOnClickListener { vm.activate() }
        binding.btnRenew.setOnClickListener    { showRenewDialog() }
        binding.btnEdit.setOnClickListener     { showEditDialog() }
        binding.btnRecordPayment.setOnClickListener { showPaymentDialog() }
        binding.btnDelete.setOnClickListener   {
            AlertDialog.Builder(requireContext())
                .setTitle("Delete Client")
                .setMessage("This will permanently delete this client and all associated records.")
                .setPositiveButton("Delete") { _, _ -> vm.delete() }
                .setNegativeButton("Cancel", null)
                .show()
        }
    }

    private fun renderClient(c: Client) {
        binding.tvError.visibility = View.GONE
        binding.tvClientName.text  = c.full_name
        binding.tvUsername.text    = "@${c.username}"
        binding.tvPhone.text       = c.phone ?: "—"
        binding.tvEmail.text       = c.email ?: "—"
        binding.tvAccount.text     = c.account_number ?: "—"
        binding.tvPackage.text     = c.package_name ?: "—"
        binding.tvExpiry.text      = c.expiry_date?.take(10) ?: "—"
        binding.tvServiceType.text = c.service_type.uppercase()
        binding.tvStatus.text      = c.status.replaceFirstChar { it.uppercase() }
        val statusColor = when (c.status) {
            "active"    -> android.R.color.holo_green_dark
            "suspended" -> android.R.color.holo_orange_dark
            else        -> android.R.color.holo_red_dark
        }
        binding.tvStatus.setTextColor(requireContext().getColor(statusColor))
        binding.btnSuspend.isEnabled  = c.status == "active"
        binding.btnActivate.isEnabled = c.status != "active"
    }

    private fun showRenewDialog() {
        val view = LayoutInflater.from(requireContext()).inflate(android.R.layout.simple_list_item_1, null)
        // Simple dialog: renew by package default OR specify days
        AlertDialog.Builder(requireContext())
            .setTitle("Renew Subscription")
            .setItems(arrayOf("Renew by package validity", "Extend by 7 days", "Extend by 30 days", "Extend by 90 days")) { _, which ->
                when (which) {
                    0 -> vm.renew(0)
                    1 -> vm.renew(7)
                    2 -> vm.renew(30)
                    3 -> vm.renew(90)
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun showEditDialog() {
        val c = (vm.client.value as? Result.Success)?.data ?: return
        val ctx = requireContext()
        val dialogView = LayoutInflater.from(ctx).inflate(site.fortunetttech.admin.R.layout.dialog_edit_client, null)

        val etName    = dialogView.findViewById<EditText>(site.fortunetttech.admin.R.id.etName)
        val etPhone   = dialogView.findViewById<EditText>(site.fortunetttech.admin.R.id.etPhone)
        val etEmail   = dialogView.findViewById<EditText>(site.fortunetttech.admin.R.id.etEmail)
        val etAddress = dialogView.findViewById<EditText>(site.fortunetttech.admin.R.id.etAddress)
        val spPackage = dialogView.findViewById<Spinner>(site.fortunetttech.admin.R.id.spPackage)
        val spStatus  = dialogView.findViewById<Spinner>(site.fortunetttech.admin.R.id.spStatus)

        etName.setText(c.full_name)
        etPhone.setText(c.phone ?: "")
        etEmail.setText(c.email ?: "")

        val statuses = listOf("active","inactive","suspended","expired")
        ArrayAdapter(ctx, android.R.layout.simple_spinner_item, statuses)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item); spStatus.adapter = it }
        spStatus.setSelection(statuses.indexOf(c.status).coerceAtLeast(0))

        val packages = vm.packages.value
        val pkgNames = listOf("— keep current —") + packages.map { it.name }
        ArrayAdapter(ctx, android.R.layout.simple_spinner_item, pkgNames)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item); spPackage.adapter = it }

        AlertDialog.Builder(ctx)
            .setTitle("Edit Client")
            .setView(dialogView)
            .setPositiveButton("Save") { _, _ ->
                val pkgIdx = spPackage.selectedItemPosition
                vm.update(UpdateClientRequest(
                    id       = c.id,
                    full_name = etName.text.toString().trim().ifEmpty { null },
                    phone    = etPhone.text.toString().trim().ifEmpty { null },
                    email    = etEmail.text.toString().trim().ifEmpty { null },
                    address  = etAddress.text.toString().trim().ifEmpty { null },
                    status   = spStatus.selectedItem.toString(),
                    package_id = if (pkgIdx > 0) packages[pkgIdx - 1].id else null
                ))
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun showPaymentDialog() {
        val c = (vm.client.value as? Result.Success)?.data ?: return
        val ctx = requireContext()
        val view = LayoutInflater.from(ctx).inflate(site.fortunetttech.admin.R.layout.dialog_record_payment, null)

        val etAmount  = view.findViewById<EditText>(site.fortunetttech.admin.R.id.etAmount)
        val etRef     = view.findViewById<EditText>(site.fortunetttech.admin.R.id.etReference)
        val etNotes   = view.findViewById<EditText>(site.fortunetttech.admin.R.id.etNotes)
        val spMethod  = view.findViewById<Spinner>(site.fortunetttech.admin.R.id.spMethod)

        val methods = listOf("cash", "mpesa_manual", "bank_transfer")
        ArrayAdapter(ctx, android.R.layout.simple_spinner_item, methods)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item); spMethod.adapter = it }

        AlertDialog.Builder(ctx)
            .setTitle("Record Payment — ${c.full_name}")
            .setView(view)
            .setPositiveButton("Record") { _, _ ->
                val amount = etAmount.text.toString().toDoubleOrNull() ?: 0.0
                val ref    = etRef.text.toString().trim()
                val notes  = etNotes.text.toString().trim()
                val method = spMethod.selectedItem.toString()
                if (amount <= 0) { Toast.makeText(ctx, "Enter a valid amount", Toast.LENGTH_SHORT).show(); return@setPositiveButton }
                vm.recordPayment(RecordPaymentRequest(
                    client_id = c.id, amount = amount, method = method,
                    reference_code = ref.ifEmpty { null }, notes = notes.ifEmpty { null }
                ))
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
