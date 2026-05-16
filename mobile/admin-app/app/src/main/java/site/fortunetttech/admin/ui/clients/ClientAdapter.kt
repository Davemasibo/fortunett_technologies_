package site.fortunetttech.admin.ui.clients

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import site.fortunetttech.admin.R
import site.fortunetttech.admin.data.model.Client
import site.fortunetttech.admin.databinding.ItemClientBinding

class ClientAdapter(private val onItemClick: (Client) -> Unit) :
    ListAdapter<Client, ClientAdapter.ViewHolder>(DIFF) {

    inner class ViewHolder(private val b: ItemClientBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(client: Client) {
            b.tvClientName.text = client.full_name
            b.tvUsername.text   = "@${client.username}"
            b.tvPackage.text    = client.package_name ?: "—"
            b.tvExpiry.text     = client.expiry_date?.take(10) ?: "—"
            b.tvStatus.text     = client.status.replaceFirstChar { it.uppercase() }
            b.tvStatus.setTextColor(ContextCompat.getColor(b.root.context, statusColor(client.status)))
            b.root.setOnClickListener { onItemClick(client) }
        }

        private fun statusColor(status: String) = when (status) {
            "active"    -> R.color.status_active
            "expired"   -> R.color.status_expired
            "suspended" -> R.color.status_suspended
            else        -> R.color.status_inactive
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = ViewHolder(
        ItemClientBinding.inflate(LayoutInflater.from(parent.context), parent, false)
    )

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<Client>() {
            override fun areItemsTheSame(a: Client, b: Client) = a.id == b.id
            override fun areContentsTheSame(a: Client, b: Client) = a == b
        }
    }
}
