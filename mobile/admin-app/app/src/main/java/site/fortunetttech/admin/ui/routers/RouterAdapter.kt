package site.fortunetttech.admin.ui.routers

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import site.fortunetttech.admin.R
import site.fortunetttech.admin.data.model.Router
import site.fortunetttech.admin.databinding.ItemRouterBinding

class RouterAdapter : ListAdapter<Router, RouterAdapter.ViewHolder>(DIFF) {

    inner class ViewHolder(private val b: ItemRouterBinding) : RecyclerView.ViewHolder(b.root) {
        fun bind(r: Router) {
            b.tvRouterName.text = r.name
            b.tvHost.text       = r.host
            b.tvLastSeen.text   = if (r.last_seen != null) "Last seen: ${r.last_seen.take(16)}" else "Never connected"
            b.tvStatus.text     = r.status.replaceFirstChar { it.uppercase() }
            val isOnline = r.status == "online"
            val color = if (isOnline) R.color.status_active else R.color.status_expired
            b.tvStatusDot.setTextColor(ContextCompat.getColor(b.root.context, color))
            b.tvStatus.setTextColor(ContextCompat.getColor(b.root.context, color))
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = ViewHolder(
        ItemRouterBinding.inflate(LayoutInflater.from(parent.context), parent, false)
    )
    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<Router>() {
            override fun areItemsTheSame(a: Router, b: Router) = a.id == b.id
            override fun areContentsTheSame(a: Router, b: Router) = a == b
        }
    }
}
