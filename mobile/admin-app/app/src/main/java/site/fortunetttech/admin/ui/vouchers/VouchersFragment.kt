package site.fortunetttech.admin.ui.vouchers

import android.app.AlertDialog
import android.os.Bundle
import android.view.*
import android.widget.*
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.google.android.material.snackbar.Snackbar
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.admin.R
import site.fortunetttech.admin.data.model.*
import site.fortunetttech.admin.databinding.FragmentVouchersBinding
import site.fortunetttech.admin.util.Result

@AndroidEntryPoint
class VouchersFragment : Fragment() {

    private var _binding: FragmentVouchersBinding? = null
    private val binding get() = _binding!!
    private val vm: VouchersViewModel by viewModels()
    private val adapter = VoucherAdapter(onDelete = { v -> confirmDelete(v) })

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentVouchersBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerView.adapter       = adapter
        binding.recyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.swipeRefresh.setOnRefreshListener { vm.load() }
        binding.fabGenerate.setOnClickListener { showGenerateDialog() }

        // Status filter chips
        binding.chipAll.setOnClickListener      { vm.load(null) }
        binding.chipActive.setOnClickListener   { vm.load("active") }
        binding.chipUsed.setOnClickListener     { vm.load("used") }
        binding.chipExpired.setOnClickListener  { vm.load("expired") }

        lifecycleScope.launch {
            vm.vouchers.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> {
                        adapter.submitList(result.data.data)
                        val s = result.data.stats
                        binding.tvStats.text = "Total: ${s.total}  Active: ${s.available}  Used: ${s.used}  Expired: ${s.expired}"
                        binding.layoutError.visibility = View.GONE
                    }
                    is Result.Error -> {
                        binding.tvErrorMsg.text        = result.message
                        binding.layoutError.visibility = View.VISIBLE
                    }
                }
            }
        }

        lifecycleScope.launch {
            vm.actionResult.collectLatest { msg ->
                Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
            }
        }
    }

    private fun showGenerateDialog() {
        val ctx  = requireContext()
        val view = LayoutInflater.from(ctx).inflate(R.layout.dialog_generate_vouchers, null)

        val etCount    = view.findViewById<EditText>(R.id.etCount)
        val etPrice    = view.findViewById<EditText>(R.id.etPrice)
        val etDuration = view.findViewById<EditText>(R.id.etDuration)
        val etPrefix   = view.findViewById<EditText>(R.id.etPrefix)
        val spUnit     = view.findViewById<Spinner>(R.id.spDurationUnit)
        val spType     = view.findViewById<Spinner>(R.id.spConnectionType)
        val spPackage  = view.findViewById<Spinner>(R.id.spPackage)

        ArrayAdapter.createFromResource(ctx, R.array.validity_units, android.R.layout.simple_spinner_item)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item); spUnit.adapter = it }
        ArrayAdapter.createFromResource(ctx, R.array.connection_types, android.R.layout.simple_spinner_item)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item); spType.adapter = it }

        val packages = vm.packages.value
        val pkgNames = listOf("No package") + packages.map { "${it.name} (KSH ${it.price.toLong()})" }
        ArrayAdapter(ctx, android.R.layout.simple_spinner_item, pkgNames)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item); spPackage.adapter = it }

        AlertDialog.Builder(ctx)
            .setTitle("Generate Vouchers")
            .setView(view)
            .setPositiveButton("Generate") { _, _ ->
                val count    = etCount.text.toString().toIntOrNull()?.coerceIn(1, 500) ?: 1
                val price    = etPrice.text.toString().toDoubleOrNull() ?: 0.0
                val duration = etDuration.text.toString().toIntOrNull() ?: 1
                val unit     = spUnit.selectedItem.toString()
                val connType = spType.selectedItem.toString()
                val prefix   = etPrefix.text.toString().trim().uppercase()
                val pkgIdx   = spPackage.selectedItemPosition
                val pkgId    = if (pkgIdx > 0) packages[pkgIdx - 1].id else null

                vm.generate(GenerateVouchersRequest(
                    count = count, package_id = pkgId,
                    duration_value = duration, duration_unit = unit,
                    price = price, connection_type = connType,
                    prefix = prefix.ifEmpty { null }
                ))
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun confirmDelete(v: Voucher) {
        AlertDialog.Builder(requireContext())
            .setTitle("Delete Voucher")
            .setMessage("Delete voucher ${v.voucher_code}?")
            .setPositiveButton("Delete") { _, _ -> vm.delete(listOf(v.id)) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
