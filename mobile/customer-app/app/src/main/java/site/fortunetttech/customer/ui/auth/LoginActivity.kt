package site.fortunetttech.customer.ui.auth

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch
import site.fortunetttech.customer.MainActivity
import site.fortunetttech.customer.data.preferences.TokenPreferences
import site.fortunetttech.customer.databinding.ActivityLoginBinding
import javax.inject.Inject

@AndroidEntryPoint
class LoginActivity : AppCompatActivity() {

    @Inject lateinit var prefs: TokenPreferences

    private lateinit var binding: ActivityLoginBinding
    private val vm: LoginViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (prefs.isLoggedIn) { goToMain(); return }

        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnLogin.setOnClickListener {
            vm.login(
                binding.etSubdomain.text.toString(),
                binding.etUsername.text.toString(),
                binding.etPassword.text.toString()
            )
        }

        lifecycleScope.launch {
            vm.state.collectLatest { state ->
                when (state) {
                    is LoginViewModel.UiState.Idle    -> setLoading(false)
                    is LoginViewModel.UiState.Loading -> setLoading(true)
                    is LoginViewModel.UiState.Success -> { setLoading(false); goToMain() }
                    is LoginViewModel.UiState.Error   -> {
                        setLoading(false)
                        binding.tvError.text       = state.message
                        binding.tvError.visibility = View.VISIBLE
                    }
                }
            }
        }
    }

    private fun setLoading(loading: Boolean) {
        binding.btnLogin.isEnabled     = !loading
        binding.progressBar.visibility = if (loading) View.VISIBLE else View.GONE
        binding.tvError.visibility     = View.GONE
    }

    private fun goToMain() {
        startActivity(Intent(this, MainActivity::class.java))
        finish()
    }
}
