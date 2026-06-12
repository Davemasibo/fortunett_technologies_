package site.fortunetttech.customer.ui.profile

import android.app.AlertDialog
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.lifecycleScope
import com.google.android.material.snackbar.Snackbar
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.customer.data.model.Session
import site.fortunetttech.customer.databinding.FragmentProfileBinding
import site.fortunetttech.customer.util.Result

@AndroidEntryPoint
class ProfileFragment : Fragment() {

    private var _binding: FragmentProfileBinding? = null
    private val binding get() = _binding!!
    private val vm: ProfileViewModel by viewModels()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentProfileBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.swipeRefresh.setOnRefreshListener { vm.load() }

        lifecycleScope.launch {
            vm.profile.collectLatest { result ->
                binding.swipeRefresh.isRefreshing = false
                when (result) {
                    null -> {}
                    is Result.Success -> {
                        val p = result.data
                        binding.tvName.text         = p.full_name
                        binding.tvUsername.text     = "@${p.username}"
                        binding.tvPhone.text        = p.phone ?: "—"
                        binding.tvEmail.text        = p.email ?: "—"
                        binding.tvAccount.text      = p.account_number ?: "—"
                        binding.tvStatus.text       = p.status.replaceFirstChar { it.uppercase() }
                        binding.tvPackage.text      = p.subscription.package_name ?: "No plan"
                        binding.tvExpiry.text       = p.subscription.expiry_date?.take(10) ?: "—"
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

        lifecycleScope.launch {
            vm.message.collectLatest { msg ->
                Snackbar.make(binding.root, msg, Snackbar.LENGTH_LONG).show()
            }
        }

        lifecycleScope.launch {
            vm.sessions.collectLatest { sessions -> renderSessions(sessions) }
        }

        binding.btnEditProfile.setOnClickListener { showEditDialog() }
        binding.btnChangePassword.setOnClickListener { showPasswordDialog() }
    }

    private fun showEditDialog() {
        val p = (vm.profile.value as? Result.Success)?.data ?: return
        val ctx = requireContext()
        val view = LayoutInflater.from(ctx).inflate(site.fortunetttech.customer.R.layout.dialog_edit_profile, null)

        val etName  = view.findViewById<EditText>(site.fortunetttech.customer.R.id.etName)
        val etPhone = view.findViewById<EditText>(site.fortunetttech.customer.R.id.etPhone)
        val etEmail = view.findViewById<EditText>(site.fortunetttech.customer.R.id.etEmail)

        etName.setText(p.full_name)
        etPhone.setText(p.phone ?: "")
        etEmail.setText(p.email ?: "")

        AlertDialog.Builder(ctx)
            .setTitle("Edit Profile")
            .setView(view)
            .setPositiveButton("Save") { _, _ ->
                vm.updateProfile(
                    etName.text.toString().trim(),
                    etPhone.text.toString().trim(),
                    etEmail.text.toString().trim()
                )
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun showPasswordDialog() {
        val ctx  = requireContext()
        val view = LayoutInflater.from(ctx).inflate(site.fortunetttech.customer.R.layout.dialog_change_password, null)

        val etCurrent = view.findViewById<EditText>(site.fortunetttech.customer.R.id.etCurrentPassword)
        val etNew     = view.findViewById<EditText>(site.fortunetttech.customer.R.id.etNewPassword)
        val etConfirm = view.findViewById<EditText>(site.fortunetttech.customer.R.id.etConfirmPassword)

        AlertDialog.Builder(ctx)
            .setTitle("Change Password")
            .setView(view)
            .setPositiveButton("Change") { _, _ ->
                val current = etCurrent.text.toString()
                val new     = etNew.text.toString()
                val confirm = etConfirm.text.toString()
                if (new != confirm) {
                    Snackbar.make(binding.root, "Passwords do not match", Snackbar.LENGTH_SHORT).show()
                    return@setPositiveButton
                }
                if (new.length < 6) {
                    Snackbar.make(binding.root, "Password must be at least 6 characters", Snackbar.LENGTH_SHORT).show()
                    return@setPositiveButton
                }
                vm.changePassword(current, new)
            }
            .setNegativeButton("Cancel", null)
            .show()
    }

    private fun renderSessions(sessions: List<Session>) {
        val ctx = requireContext()
        binding.layoutSessions.removeAllViews()
        if (sessions.isEmpty()) {
            binding.tvNoSessions.visibility = View.VISIBLE
            return
        }
        binding.tvNoSessions.visibility = View.GONE
        sessions.take(5).forEach { s ->
            val row = LinearLayout(ctx).apply {
                orientation = LinearLayout.VERTICAL
                val lp = LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT)
                lp.bottomMargin = (10 * resources.displayMetrics.density).toInt()
                layoutParams = lp
            }
            val tvIp = TextView(ctx).apply {
                text = s.ip_address
                textSize = 13f
                setTextColor(android.graphics.Color.parseColor("#e2e2e0"))
            }
            val tvTime = TextView(ctx).apply {
                text = s.last_activity?.take(16) ?: s.created_at.take(16)
                textSize = 11f
                setTextColor(android.graphics.Color.parseColor("#888888"))
            }
            row.addView(tvIp)
            row.addView(tvTime)
            binding.layoutSessions.addView(row)
        }
    }

    override fun onDestroyView() { super.onDestroyView(); _binding = null }
}
