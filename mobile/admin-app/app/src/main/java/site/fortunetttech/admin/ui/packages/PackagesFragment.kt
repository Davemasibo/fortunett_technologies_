package site.fortunetttech.admin.ui.packages

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
import site.fortunetttech.admin.databinding.FragmentPackagesBinding
import site.fortunetttech.admin.util.Result

@AndroidEntryPoint
class PackagesFragment : Fragment() {

    private var _binding: FragmentPackagesBinding? = null
    private val binding get() = _binding!!
    private val vm: PackagesViewModel by viewModels()
    private val adapter = PackageAdapter(
        onEdit   = { pkg -> showPackageDialog(pkg) },
        onDelete = { pkg -> confirmDelete(pkg) }
    )

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentPackagesBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerView.adapter       = adapter
        binding.recyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.swipeRefresh.setOnRefreshListener { vm.load() }
        binding.fabAdd.setOnClickListener { showPackageDialog(null) }

        lifecycleScope.launch {
            vm.packages.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> {
                        adapter.submitList(result.data.data)
                        binding.tvCount.text = "${result.data.pagination.total} packages"
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

    private fun showPackageDialog(pkg: Package?) {
        val ctx   = requireContext()
        val isNew = pkg == null
        val view  = LayoutInflater.from(ctx).inflate(R.layout.dialog_package_form, null)

        val etName       = view.findViewById<EditText>(R.id.etName)
        val etPrice      = view.findViewById<EditText>(R.id.etPrice)
        val etDownload   = view.findViewById<EditText>(R.id.etDownloadSpeed)
        val etUpload     = view.findViewById<EditText>(R.id.etUploadSpeed)
        val etValidity   = view.findViewById<EditText>(R.id.etValidityValue)
        val spType       = view.findViewById<Spinner>(R.id.spConnectionType)
        val spUnit       = view.findViewById<Spinner>(R.id.spValidityUnit)
        val spStatus     = view.findViewById<Spinner>(R.id.spStatus)
        val etDesc       = view.findViewById<EditText>(R.id.etDescription)

        ArrayAdapter.createFromResource(ctx, R.array.connection_types, android.R.layout.simple_spinner_item)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item); spType.adapter = it }
        ArrayAdapter.createFromResource(ctx, R.array.validity_units, android.R.layout.simple_spinner_item)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item); spUnit.adapter = it }
        ArrayAdapter.createFromResource(ctx, R.array.status_options, android.R.layout.simple_spinner_item)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item); spStatus.adapter = it }

        if (pkg != null) {
            etName.setText(pkg.name)
            etPrice.setText(pkg.price.toLong().toString())
            etDownload.setText(pkg.download_speed.toString())
            etUpload.setText(pkg.upload_speed.toString())
            etValidity.setText(pkg.validity_value.toString())
            etDesc.setText(pkg.description ?: "")
            val types = listOf("pppoe", "hotspot")
            spType.setSelection(types.indexOf(pkg.connection_type).coerceAtLeast(0))
            val units = listOf("days", "months", "hours")
            spUnit.setSelection(units.indexOf(pkg.validity_unit).coerceAtLeast(0))
            val statuses = listOf("active", "inactive")
            spStatus.setSelection(statuses.indexOf(pkg.status).coerceAtLeast(0))
        }

        AlertDialog.Builder(ctx)
            .setTitle(if (isNew) "Add Package" else "Edit Package")
            .setView(view)
            .setPositiveButton(if (isNew) "Create" else "Save") { _, _ ->
                val name     = etName.text.toString().trim()
                val price    = etPrice.text.toString().toDoubleOrNull() ?: 0.0
                val download = etDownload.text.toString().toIntOrNull() ?: 0
                val upload   = etUpload.text.toString().toIntOrNull() ?: 0
                val validity = etValidity.text.toString().toIntOrNull() ?: 30
                val connType = spType.selectedItem.toString()
                val unit     = spUnit.selectedItem.toString()
                val status   = spStatus.selectedItem.toString()
                val desc     = etDesc.text.toString().trim()

                if (name.isEmpty() || price <= 0) {
                    Toast.makeText(ctx, "Name and price are required", Toast.LENGTH_SHORT).show()
                    return@setPositiveButton
                }

                if (isNew) {
                    vm.createPackage(CreatePackageRequest(
                        name = name, price = price, connection_type = connType,
                        download_speed = download, upload_speed = upload,
                        validity_value = validity, validity_unit = unit,
                        description = desc.ifEmpty { null }
                    ))
                } else {
                    vm.updatePackage(UpdatePackageRequest(
                        id = pkg!!.id, name = name, price = price, connection_type = connType,
                        download_speed = download, upload_speed = upload,
                        validity_value = validity, validity_unit = unit,
                        status = status, description = desc.ifEmpty { null }
                    ))
                }
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun confirmDelete(pkg: Package) {
        AlertDialog.Builder(requireContext())
            .setTitle("Delete Package")
            .setMessage("Delete \"${pkg.name}\"? This cannot be undone.")
            .setPositiveButton("Delete") { _, _ -> vm.deletePackage(pkg.id) }
            .setNegativeButton("Cancel", null)
            .show()
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
