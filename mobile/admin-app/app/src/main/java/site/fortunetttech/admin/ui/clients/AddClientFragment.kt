package site.fortunetttech.admin.ui.clients

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
import site.fortunetttech.admin.data.model.CreateClientRequest
import site.fortunetttech.admin.databinding.FragmentAddClientBinding

@AndroidEntryPoint
class AddClientFragment : Fragment() {

    private var _binding: FragmentAddClientBinding? = null
    private val binding get() = _binding!!
    private val vm: AddClientViewModel by viewModels()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentAddClientBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        lifecycleScope.launch {
            vm.packages.collectLatest { packages ->
                val names = packages.map { "${it.name} — KSH ${it.price.toLong()} / ${it.validity_value} ${it.validity_unit}" }
                ArrayAdapter(requireContext(), android.R.layout.simple_spinner_item, names)
                    .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item)
                        binding.spPackage.adapter = it }
            }
        }

        val connTypes = listOf("pppoe", "hotspot")
        ArrayAdapter(requireContext(), android.R.layout.simple_spinner_item, connTypes)
            .also { it.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item)
                binding.spConnectionType.adapter = it }

        binding.btnSave.setOnClickListener { submit() }
        binding.btnCancel.setOnClickListener { findNavController().popBackStack() }

        lifecycleScope.launch {
            vm.loading.collectLatest { loading ->
                binding.btnSave.isEnabled = !loading
                binding.progressBar.visibility = if (loading) View.VISIBLE else View.GONE
            }
        }

        lifecycleScope.launch {
            vm.result.collectLatest { (success, msg) ->
                if (success) {
                    Snackbar.make(binding.root, msg, Snackbar.LENGTH_SHORT).show()
                    findNavController().popBackStack()
                } else {
                    Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
                }
            }
        }
    }

    private fun submit() {
        val name  = binding.etName.text.toString().trim()
        val phone = binding.etPhone.text.toString().trim()
        val email = binding.etEmail.text.toString().trim()
        val packages = vm.packages.value

        if (name.isEmpty()) { binding.etName.error = "Required"; return }
        if (packages.isEmpty()) { Snackbar.make(binding.root, "No packages available", Snackbar.LENGTH_SHORT).show(); return }

        val pkgIdx   = binding.spPackage.selectedItemPosition
        val pkg      = packages.getOrNull(pkgIdx) ?: return
        val connType = binding.spConnectionType.selectedItem.toString()

        vm.createClient(CreateClientRequest(
            full_name = name,
            phone     = phone,
            email     = email.ifEmpty { null },
            package_id = pkg.id,
            connection_type = connType
        ))
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
