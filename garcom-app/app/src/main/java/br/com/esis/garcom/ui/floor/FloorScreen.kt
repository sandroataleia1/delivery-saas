package br.com.esis.garcom.ui.floor

import android.widget.Toast
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import br.com.esis.garcom.R
import br.com.esis.garcom.data.FloorTable
import br.com.esis.garcom.data.Waiter
import br.com.esis.garcom.ui.theme.Brand
import br.com.esis.garcom.ui.theme.LocalDarkTheme
import coil.compose.AsyncImage
import kotlinx.coroutines.delay

private const val REFRESH_INTERVAL_MS = 10_000L

@Composable
fun FloorScreen(viewModel: FloorViewModel, waiter: Waiter, onLoggedOut: () -> Unit) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val lifecycleOwner = LocalLifecycleOwner.current
    val context = LocalContext.current
    var confirmSwitch by remember { mutableStateOf(false) }
    var filter by rememberSaveable { mutableStateOf(TableFilter.ALL) }

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

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Header(waiter, onSwitch = { confirmSwitch = true })

        val showing = state as? FloorState.Showing ?: return@Column
        showing.error?.let { ErrorBanner(it, onRetry = viewModel::retry) }

        if (showing.loading && showing.tables.isEmpty()) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        } else if (showing.tables.isEmpty() && showing.error == null) {
            EmptyMessage("Nenhuma mesa cadastrada. Cadastre as mesas no painel do restaurante.")
        } else {
            FilterChips(showing.tables, selected = filter, onSelect = { filter = it })
            val visible = showing.tables.filteredBy(filter)
            if (visible.isEmpty()) {
                EmptyMessage("Nenhuma mesa em \"${filter.label}\" agora.")
            } else {
                LazyVerticalGrid(
                    columns = GridCells.Adaptive(minSize = 104.dp),
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 16.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    items(visible, key = { it.id }) { table ->
                        TableCard(table, onClick = { Toast.makeText(context, "Em breve", Toast.LENGTH_SHORT).show() })
                    }
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
    Surface(color = MaterialTheme.colorScheme.surface) {
        Column {
            Row(
                modifier = Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 14.dp, bottom = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                CompanyLogo(waiter)
                Column(Modifier.weight(1f)) {
                    Text(
                        waiter.companyName,
                        fontSize = 17.sp,
                        fontWeight = FontWeight.Bold,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                    Text(
                        "${waiter.name} · garçom",
                        fontSize = 13.sp,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
                OutlinedButton(
                    onClick = onSwitch,
                    modifier = Modifier.height(44.dp),
                    shape = CircleShape,
                    border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
                    colors = ButtonDefaults.outlinedButtonColors(contentColor = MaterialTheme.colorScheme.onSurface),
                    contentPadding = PaddingValues(horizontal = 14.dp),
                ) {
                    Icon(painterResource(R.drawable.ic_switch), contentDescription = null, modifier = Modifier.size(18.dp))
                    Text("Trocar", fontSize = 14.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(start = 8.dp))
                }
            }
            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
        }
    }
}

/** Logo do restaurante; sem logo cadastrada, as iniciais do nome num quadrado da cor da marca. */
@Composable
private fun CompanyLogo(waiter: Waiter) {
    val shape = RoundedCornerShape(12.dp)
    if (waiter.logoUrl != null) {
        AsyncImage(
            model = waiter.logoUrl,
            contentDescription = "Logo de ${waiter.companyName}",
            modifier = Modifier.size(44.dp).clip(shape),
        )
    } else {
        Box(
            modifier = Modifier.size(44.dp).clip(shape).background(Brand)
                .clearAndSetSemantics { contentDescription = "Logo de ${waiter.companyName}" },
            contentAlignment = Alignment.Center,
        ) {
            Text(initials(waiter.companyName), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
        }
    }
}

private fun initials(name: String): String =
    name.split(' ').filter { it.isNotBlank() }.take(2).joinToString("") { it.first().uppercase() }

@Composable
private fun FilterChips(tables: List<FloorTable>, selected: TableFilter, onSelect: (TableFilter) -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 12.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        TableFilter.entries.forEach { option ->
            val isSelected = option == selected
            val count = tables.filteredBy(option).size
            Surface(
                onClick = { onSelect(option) },
                modifier = Modifier.height(44.dp).semantics {
                    role = Role.Tab
                    this.selected = isSelected
                },
                shape = CircleShape,
                color = if (isSelected) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.surface,
                contentColor = if (isSelected) MaterialTheme.colorScheme.surface else MaterialTheme.colorScheme.onSurface,
                border = if (isSelected) null else BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
            ) {
                Row(
                    modifier = Modifier.padding(horizontal = 14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    if (option == TableFilter.ATTENTION) {
                        Box(Modifier.size(8.dp).clip(CircleShape).background(Color(0xFFDC2626)))
                    }
                    Text("${option.label} · $count", fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@Composable
private fun ErrorBanner(message: String, onRetry: () -> Unit) {
    Surface(color = MaterialTheme.colorScheme.errorContainer, contentColor = MaterialTheme.colorScheme.onErrorContainer) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 4.dp, bottom = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Icon(painterResource(R.drawable.ic_alert), contentDescription = null, modifier = Modifier.size(18.dp))
            Text(message, fontSize = 14.sp, modifier = Modifier.weight(1f))
            TextButton(onClick = onRetry) { Text("Tentar de novo", fontWeight = FontWeight.SemiBold) }
        }
    }
}

@Composable
private fun EmptyMessage(text: String) {
    Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
        Text(text, textAlign = TextAlign.Center, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun TableCard(table: FloorTable, onClick: () -> Unit) {
    val style = table.status.style(LocalDarkTheme.current)
    val info = tableInfo(table)

    Surface(
        onClick = onClick,
        modifier = Modifier.height(112.dp).clearAndSetSemantics {
            role = Role.Button
            contentDescription = listOf("Mesa ${table.number}", style.label, info).filter { it.isNotEmpty() }.joinToString(", ")
        },
        shape = RoundedCornerShape(16.dp),
        color = style.container,
        contentColor = style.content,
        border = BorderStroke(1.5.dp, style.border),
    ) {
        Column(Modifier.fillMaxSize().padding(12.dp), verticalArrangement = Arrangement.SpaceBetween) {
            Text(table.number.toString(), fontSize = 30.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 30.sp)
            Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
                    style.icon?.let { Icon(painterResource(it), contentDescription = null, modifier = Modifier.size(14.dp)) }
                    Text(style.label, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                if (info.isNotEmpty()) {
                    Text(
                        info,
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Medium,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        color = style.content.copy(alpha = 0.85f),
                    )
                }
            }
        }
    }
}
