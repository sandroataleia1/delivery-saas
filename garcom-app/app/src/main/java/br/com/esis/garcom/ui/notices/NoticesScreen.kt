package br.com.esis.garcom.ui.notices

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.esis.garcom.data.Notice
import br.com.esis.garcom.ui.floor.ErrorBanner
import br.com.esis.garcom.ui.order.BackHeader
import br.com.esis.garcom.ui.theme.Brand

/** Avisos do garçom. Tocar num aviso de mesa abre a comanda; se ela já fechou, a comanda avisa e volta ao salão. */
@Composable
fun NoticesScreen(viewModel: NoticesViewModel, onBack: () -> Unit, onOpenOrder: (tableId: Long, orderId: Long) -> Unit) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BackHeader(title = "Avisos", subtitle = null, onBack = onBack)
        state.error?.let { ErrorBanner(it, onRetry = viewModel::retry) }

        when {
            state.loading && state.items.isEmpty() ->
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            state.items.isEmpty() ->
                Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
                    Text(
                        "Nenhum aviso por enquanto.\nPedidos pelo QR e itens prontos aparecem aqui.",
                        textAlign = TextAlign.Center,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            else -> LazyColumn(contentPadding = PaddingValues(vertical = 4.dp)) {
                items(state.items, key = { it.id }) { notice ->
                    val tableId = notice.tableId
                    val orderId = notice.orderId
                    NoticeRow(notice, onClick = if (tableId != null && orderId != null) ({ onOpenOrder(tableId, orderId) }) else null)
                    HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
                }
            }
        }
    }
}

@Composable
private fun NoticeRow(notice: Notice, onClick: (() -> Unit)?) {
    val clickable = if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier
    Row(
        modifier = Modifier.fillMaxWidth().then(clickable).padding(horizontal = 16.dp, vertical = 14.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.padding(top = 7.dp).size(8.dp).clip(CircleShape).background(if (notice.read) MaterialTheme.colorScheme.background else Brand))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(notice.title, fontSize = 16.sp, fontWeight = if (notice.read) FontWeight.Normal else FontWeight.Bold)
            notice.body?.let { Text(it, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        }
        Text(relativeTime(notice.createdAt), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}
