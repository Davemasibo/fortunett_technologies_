package site.fortunetttech.customer.ui.home

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.lifecycleScope
import androidx.navigation.fragment.findNavController
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.customer.MainActivity
import site.fortunetttech.customer.R
import site.fortunetttech.customer.databinding.FragmentHomeBinding
import site.fortunetttech.customer.util.Result
import java.util.concurrent.TimeUnit

@AndroidEntryPoint
class HomeFragment : Fragment() {

    private var _binding: FragmentHomeBinding? = null
    private val binding get() = _binding!!
    private val vm: HomeViewModel by viewModels()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentHomeBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.swipeRefresh.setOnRefreshListener { vm.load() }

        binding.btnPay.setOnClickListener {
            findNavController().navigate(R.id.action_home_to_pay)
        }

        binding.btnLogout.setOnClickListener { vm.logout() }

        lifecycleScope.launch {
            vm.loggedOut.collectLatest { if (it) (activity as? MainActivity)?.navigateToLogin() }
        }

        lifecycleScope.launch {
            vm.profile.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> {
                        val p = result.data
                        val s = p.subscription
                        binding.tvName.text          = "Hello, ${p.full_name.split(" ").first()}"
                        binding.tvIsp.text           = p.isp.name
                        binding.tvPackage.text       = s.package_name ?: "No active plan"
                        binding.tvExpiry.text        = s.expiry_date?.take(10) ?: "—"
                        binding.tvStatus.text        = s.status.replaceFirstChar { it.uppercase() }
                        binding.tvCountdown.text     = formatCountdown(s.expiresInSeconds)
                        binding.tvAccountNumber.text = p.account_number ?: "—"
                        binding.tvPhone.text         = p.phone ?: "—"
                        val statusColor = when (s.status) {
                            "active" -> R.color.status_active
                            else     -> R.color.status_expired
                        }
                        binding.tvStatus.setTextColor(ContextCompat.getColor(requireContext(), statusColor))
                        binding.cardSubscription.strokeColor = ContextCompat.getColor(requireContext(), statusColor)
                        binding.layoutContent.visibility = View.VISIBLE
                        binding.layoutError.visibility   = View.GONE
                    }
                    is Result.Error -> {
                        binding.tvErrorMsg.text          = result.message
                        binding.layoutError.visibility   = View.VISIBLE
                        binding.layoutContent.visibility = View.GONE
                    }
                }
            }
        }
    }

    private fun formatCountdown(seconds: Long?): String {
        if (seconds == null || seconds <= 0) return "Expired"
        val days  = TimeUnit.SECONDS.toDays(seconds)
        val hours = TimeUnit.SECONDS.toHours(seconds) % 24
        return when {
            days > 0  -> "Expires in $days day${if (days != 1L) "s" else ""}"
            hours > 0 -> "Expires in $hours hour${if (hours != 1L) "s" else ""}"
            else      -> "Expiring soon"
        }
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
