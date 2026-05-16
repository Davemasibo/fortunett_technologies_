package site.fortunetttech.customer.ui.pay

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.lifecycleScope
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.customer.data.preferences.TokenPreferences
import site.fortunetttech.customer.databinding.FragmentPayBinding
import javax.inject.Inject

@AndroidEntryPoint
class PayFragment : Fragment() {

    @Inject lateinit var prefs: TokenPreferences

    private var _binding: FragmentPayBinding? = null
    private val binding get() = _binding!!
    private val vm: PayViewModel by viewModels()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentPayBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        prefs.phone?.let { binding.etPhone.setText(it) }

        binding.btnPay.setOnClickListener {
            vm.pay(binding.etPhone.text.toString())
        }

        binding.btnReset.setOnClickListener { vm.reset() }

        lifecycleScope.launch {
            vm.state.collectLatest { state ->
                when (state) {
                    is PayViewModel.UiState.Idle -> {
                        binding.layoutResult.visibility = View.GONE
                        binding.btnPay.isEnabled        = true
                        binding.progressBar.visibility  = View.GONE
                    }
                    is PayViewModel.UiState.Loading -> {
                        binding.btnPay.isEnabled       = false
                        binding.progressBar.visibility = View.VISIBLE
                        binding.layoutResult.visibility = View.GONE
                    }
                    is PayViewModel.UiState.Success -> {
                        binding.progressBar.visibility  = View.GONE
                        binding.tvResult.text           = state.message
                        binding.tvResult.setTextColor(requireContext().getColor(android.R.color.holo_green_dark))
                        binding.layoutResult.visibility = View.VISIBLE
                        binding.btnPay.isEnabled        = false
                    }
                    is PayViewModel.UiState.Error -> {
                        binding.progressBar.visibility  = View.GONE
                        binding.tvResult.text           = state.message
                        binding.tvResult.setTextColor(requireContext().getColor(android.R.color.holo_red_dark))
                        binding.layoutResult.visibility = View.VISIBLE
                        binding.btnPay.isEnabled        = true
                    }
                }
            }
        }
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
