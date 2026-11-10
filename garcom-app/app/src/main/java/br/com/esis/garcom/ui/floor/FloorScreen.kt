package br.com.esis.garcom.ui.floor

import android.widget.Toast
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import br.com.esis.garcom.data.FloorTable
import br.com.esis.garcom.data.TableStatus
import br.com.esis.garcom.data.Waiter
import coil.compose.AsyncImage
import kotlinx.coroutines.delay
import java.text.NumberFormat
import java.util.Locale

private const val REFRESH_INTERVAL_MS = 10_000L

@Composable
fun FloorScreen(viewModel: FloorViewModel, waiter: Waiter, onLoggedOut: () -> Unit) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val lifecycleOwner = LocalLifecycleOwner.current
    val context = LocalContext.current
    var confirmSwitch by remember { mutableStateOf(false) }

    LaunchedEffect(lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            while (true) {
                viewModel.refresh()
                delay(REFRESH_INTERVAL_MS)
            }
        }
    }
    LaunchedEffect(state) {
        if (state == FloorState.LoggedOut) {
            onLoggedOut()
        }
    }

    Column(Modifier.fillMaxSize()) {
        Header(waiter, onSwitch = { confirmSwitch = true })

        val showing = state as? FloorState.Showing ?: return@Column
        showing.error?.let { ErrorBanner(it, onRetry = viewModel::retry) }

        if (showing.loading && showing.tables.isEmpty()) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        } else if (showing.tables.isEmpty() && showing.error == null) {
            Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
                Text("Nenhuma mesa cadastrada. Cadastre as mesas no painel do restaurante.")
            }
        } else {
            LazyVerticalGrid(
                columns = GridCells.Adaptive(minSize = 104.dp),
                contentPadding = PaddingValues(12.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(showing.tables, key = { it.id }) { table ->
                    TableCard(table, onClick = { Toast.makeText(context, "Em breve", Toast.LENGTH_SHORT).show() })
                }
            }
        }
    }

    if (confirmSwitch) {
        AlertDialog(
            onDismissRequest = { confirmSwitch = false },
            title = { Text("Trocar garçom?") },
            text = { Text("${waiter.name} sai deste celular e outro garçom entra com o próprio usuário.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmSwitch = false
                    viewModel.switchWaiter()
                }) { Text("Trocar") }
            },
            dismissButton = { TextButton(onClick = { confirmSwitch = false }) { Text("Cancelar") } },
        )
    }
}

@Composable
private fun Header(waiter: Waiter, onSwitch: () -> Unit) {
    Surface(tonalElevation = 2.dp) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (waiter.logoUrl != null) {
                AsyncImage(
                    model = waiter.logoUrl,
                    contentDescription = "Logo de ${waiter.companyName}",
                    modifier = Modifier.size(40.dp).clip(RoundedCornerShape(8.dp)),
                )
            }
            Column(Modifier.weight(1f)) {
                Text(
                    waiter.companyName,
                    style = MaterialTheme.typography.titleMedium,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(waiter.name, style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            TextButton(onClick = onSwitch) { Text("Trocar garçom") }
        }
    }
}

@Composable
private fun ErrorBanner(message: String, onRetry: () -> Unit) {
    Surface(color = MaterialTheme.colorScheme.errorContainer) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 4.dp, bottom = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                message,
                color = MaterialTheme.colorScheme.onErrorContainer,
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier.weight(1f),
            )
            TextButton(onClick = onRetry) { Text("Tentar de novo") }
        }
    }
}

@Composable
private fun TableCard(table: FloorTable, onClick: () -> Unit) {
    val style = table.status.style()

    Card(
        modifier = Modifier.aspectRatio(1f).clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = style.container, contentColor = style.content),
    ) {
        Column(
            modifier = Modifier.fillMaxSize().padding(10.dp),
            verticalArrangement = Arrangement.SpaceBetween,
        ) {
            Text(table.number.toString(), fontSize = 30.sp, fontWeight = FontWeight.Bold)
            Column {
                Text(style.label, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold)
                if (table.status != TableStatus.FREE) {
                    val details = listOfNotNull(table.minutes?.let { "$it min" }, table.total?.let(::money)).joinToString(" · ")
                    if (details.isNotEmpty()) {
                        Text(details, style = MaterialTheme.typography.bodySmall, maxLines = 1)
                    }
                    table.waiter?.let {
                        Text(it, style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        }
    }
}

private fun money(value: String): String? =
    value.toBigDecimalOrNull()?.let { NumberFormat.getCurrencyInstance(Locale.forLanguageTag("pt-BR")).format(it) }
