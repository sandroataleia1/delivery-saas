package br.com.esis.garcom.ui.order

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedIconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import br.com.esis.garcom.R
import br.com.esis.garcom.data.FloorTable
import br.com.esis.garcom.data.ItemStatus
import br.com.esis.garcom.data.OrderItem
import br.com.esis.garcom.data.PendingReceipt
import br.com.esis.garcom.data.TableOrder
import br.com.esis.garcom.data.TableStatus
import br.com.esis.garcom.data.TablesResult
import br.com.esis.garcom.ui.floor.ErrorBanner
import br.com.esis.garcom.ui.floor.money
import kotlinx.coroutines.delay

private const val REFRESH_INTERVAL_MS = 10_000L

/**
 * [canDeliver] e [canConfirm] vêm das permissões do garçom: sem elas, os botões de entrega e do pedido do QR não aparecem.
 * [freeTables] carrega as mesas livres para a troca de mesa.
 */
@Composable
fun OrderScreen(
    viewModel: OrderViewModel,
    tableNumber: Int,
    canDeliver: Boolean,
    canConfirm: Boolean,
    freeTables: suspend () -> TablesResult,
    onBack: () -> Unit,
    onClosed: (String) -> Unit,
    onAddItems: (orderId: Long, tableNumber: Int) -> Unit,
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val lifecycleOwner = LocalLifecycleOwner.current
    val context = LocalContext.current
    var transferring by rememberSaveable { mutableStateOf(false) }
    var rejecting by rememberSaveable { mutableStateOf<Long?>(null) }

    LaunchedEffect(lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
            while (true) {
                viewModel.refresh()
                delay(REFRESH_INTERVAL_MS)
            }
        }
    }
    LaunchedEffect(state) {
        (state as? OrderState.Closed)?.let { onClosed(it.message) }
    }

    val message = (state as? OrderState.Showing)?.message
    LaunchedEffect(message) {
        message?.let {
            Toast.makeText(context, it, Toast.LENGTH_SHORT).show()
            viewModel.dismissMessage()
        }
    }

    val showing = state as? OrderState.Showing
    val order = showing?.order

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BackHeader(
            title = (order?.table ?: tableNumber.takeIf { it > 0 })?.let { "Mesa $it" } ?: "Mesa",
            subtitle = order?.let(::orderSubtitle),
            onBack = onBack,
            actions = {
                if (showing != null) {
                    OrderMenu(
                        closing = showing.order.closing,
                        enabled = !showing.busy,
                        onToggleBill = viewModel::toggleBill,
                        onPrintBill = viewModel::printBill,
                        onTransfer = { transferring = true },
                    )
                }
            },
        )

        when (val current = state) {
            is OrderState.Showing -> {
                if (current.busy) {
                    LinearProgressIndicator(Modifier.fillMaxWidth())
                }
                current.error?.let { ErrorBanner(it, onRetry = viewModel::retry) }
                if (current.order.closing) {
                    ClosingBanner()
                }
                OrderBody(
                    order = current.order,
                    busy = current.busy,
                    canDeliver = canDeliver,
                    canConfirm = canConfirm,
                    onDeliver = { viewModel.deliver(listOf(it)) },
                    onDeliverAll = viewModel::deliverAllReady,
                    onConfirm = viewModel::confirmReceipt,
                    onReject = { rejecting = it },
                    modifier = Modifier.weight(1f),
                )
                Footer(current.order, onAddItems = { onAddItems(current.order.id, current.order.table) })
            }
            is OrderState.Loading -> {
                current.error?.let { ErrorBanner(it, onRetry = viewModel::retry) }
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            }
            is OrderState.AskPeople -> PeopleDialog(current, onOpen = viewModel::open, onDismiss = onBack)
            is OrderState.Closed -> Unit
        }
    }

    if (transferring && showing != null) {
        TransferDialog(
            freeTables = freeTables,
            onPick = { table ->
                transferring = false
                viewModel.transfer(table.id)
            },
            onDismiss = { transferring = false },
        )
    }
    rejecting?.let { receiptId ->
        RejectDialog(
            onReject = { reason ->
                rejecting = null
                viewModel.rejectReceipt(receiptId, reason)
            },
            onDismiss = { rejecting = null },
        )
    }
}

@Composable
private fun OrderMenu(closing: Boolean, enabled: Boolean, onToggleBill: () -> Unit, onPrintBill: () -> Unit, onTransfer: () -> Unit) {
    var open by remember { mutableStateOf(false) }

    Box {
        IconButton(onClick = { open = true }, enabled = enabled, modifier = Modifier.size(48.dp)) {
            Icon(painterResource(R.drawable.ic_more_vertical), contentDescription = "Mais ações da mesa", modifier = Modifier.size(24.dp))
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            DropdownMenuItem(
                text = { Text(if (closing) "Conta pedida ✓ (desfazer)" else "Pedir conta") },
                leadingIcon = { Icon(painterResource(R.drawable.ic_bell), contentDescription = null, modifier = Modifier.size(20.dp)) },
                onClick = {
                    open = false
                    onToggleBill()
                },
            )
            DropdownMenuItem(
                text = { Text("Imprimir conta") },
                leadingIcon = { Icon(painterResource(R.drawable.ic_receipt), contentDescription = null, modifier = Modifier.size(20.dp)) },
                onClick = {
                    open = false
                    onPrintBill()
                },
            )
            DropdownMenuItem(
                text = { Text("Trocar de mesa") },
                leadingIcon = { Icon(painterResource(R.drawable.ic_switch), contentDescription = null, modifier = Modifier.size(20.dp)) },
                onClick = {
                    open = false
                    onTransfer()
                },
            )
        }
    }
}

@Composable
private fun ClosingBanner() {
    Surface(color = MaterialTheme.colorScheme.tertiaryContainer, contentColor = MaterialTheme.colorScheme.onTertiaryContainer) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Icon(painterResource(R.drawable.ic_bell), contentDescription = null, modifier = Modifier.size(18.dp))
            Text("Conta pedida — o caixa já foi avisado.", fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun TransferDialog(freeTables: suspend () -> TablesResult, onPick: (FloorTable) -> Unit, onDismiss: () -> Unit) {
    var result by remember { mutableStateOf<TablesResult?>(null) }
    LaunchedEffect(Unit) { result = freeTables() }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Trocar para qual mesa?") },
        text = {
            when (val current = result) {
                null -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                is TablesResult.Loaded -> {
                    val free = current.tables.filter { it.status == TableStatus.FREE }.sortedBy { it.number }
                    if (free.isEmpty()) {
                        Text("Nenhuma mesa livre agora.")
                    } else {
                        LazyColumn(Modifier.heightIn(max = 360.dp)) {
                            items(free, key = { it.id }) { table ->
                                TextButton(onClick = { onPick(table) }, modifier = Modifier.fillMaxWidth().height(52.dp)) {
                                    Text(
                                        listOfNotNull("Mesa ${table.number}", table.area).joinToString(" · "),
                                        fontSize = 17.sp,
                                        modifier = Modifier.fillMaxWidth(),
                                    )
                                }
                            }
                        }
                    }
                }
                else -> Text("Não foi possível carregar as mesas. Confira a conexão e tente de novo.")
            }
        },
        confirmButton = {},
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancelar") } },
    )
}

@Composable
private fun RejectDialog(onReject: (String) -> Unit, onDismiss: () -> Unit) {
    var reason by rememberSaveable { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Recusar pedido do cliente") },
        text = {
            OutlinedTextField(
                value = reason,
                onValueChange = { reason = it.take(255) },
                label = { Text("Motivo (o cliente vê)") },
                minLines = 2,
                modifier = Modifier.fillMaxWidth(),
            )
        },
        confirmButton = {
            Button(onClick = { onReject(reason) }, enabled = reason.isNotBlank(), modifier = Modifier.height(48.dp)) {
                Text("Recusar", fontWeight = FontWeight.Bold)
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Voltar") } },
    )
}

private fun orderSubtitle(order: TableOrder): String = listOfNotNull(
    if (order.people == 1) "1 pessoa" else "${order.people} pessoas",
    "${order.minutes} min",
    order.waiter,
).joinToString(" · ")

@Composable
fun BackHeader(title: String, subtitle: String?, onBack: () -> Unit, actions: @Composable () -> Unit = {}) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(start = 4.dp, end = 8.dp, top = 8.dp, bottom = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onBack, modifier = Modifier.size(48.dp)) {
            Icon(painterResource(R.drawable.ic_chevron_left), contentDescription = "Voltar", modifier = Modifier.size(24.dp))
        }
        Column(Modifier.weight(1f)) {
            Text(title, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
            subtitle?.let { Text(it, fontSize = 14.sp, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        }
        actions()
    }
}

@Composable
private fun OrderBody(
    order: TableOrder,
    busy: Boolean,
    canDeliver: Boolean,
    canConfirm: Boolean,
    onDeliver: (Long) -> Unit,
    onDeliverAll: () -> Unit,
    onConfirm: (Long) -> Unit,
    onReject: (Long) -> Unit,
    modifier: Modifier,
) {
    val readyCount = order.items.count { it.deliverable }
    if (order.items.isEmpty() && order.pendingReceipts.isEmpty()) {
        Box(modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
            Text(
                "Nenhum item ainda.\nToque em Adicionar itens para lançar o pedido.",
                textAlign = TextAlign.Center,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        return
    }
    LazyColumn(modifier = modifier.fillMaxWidth(), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 4.dp)) {
        items(order.pendingReceipts, key = { "r${it.id}" }) { receipt ->
            ReceiptCard(receipt, busy = busy, canConfirm = canConfirm, onConfirm = { onConfirm(receipt.id) }, onReject = { onReject(receipt.id) })
        }
        if (canDeliver && readyCount >= 2) {
            item(key = "deliver-all") {
                Button(
                    onClick = onDeliverAll,
                    enabled = !busy,
                    shape = RoundedCornerShape(12.dp),
                    modifier = Modifier.fillMaxWidth().padding(vertical = 8.dp).height(48.dp),
                ) {
                    Icon(painterResource(R.drawable.ic_check), contentDescription = null, modifier = Modifier.size(18.dp))
                    Text("Entregar todos os prontos ($readyCount)", fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 8.dp))
                }
            }
        }
        items(order.items, key = { it.id }) { item ->
            ItemRow(item, onDeliver = if (canDeliver && item.deliverable) ({ onDeliver(item.id) }) else null, busy = busy)
            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
        }
    }
}

/** Pedido feito pelo cliente no QR da mesa, esperando o garçom conferir. */
@Composable
private fun ReceiptCard(receipt: PendingReceipt, busy: Boolean, canConfirm: Boolean, onConfirm: () -> Unit, onReject: () -> Unit) {
    Surface(
        shape = RoundedCornerShape(14.dp),
        color = MaterialTheme.colorScheme.secondaryContainer,
        contentColor = MaterialTheme.colorScheme.onSecondaryContainer,
        modifier = Modifier.fillMaxWidth().padding(vertical = 8.dp),
    ) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Pedido do cliente (QR)", fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                Text(money(receipt.total) ?: receipt.total, fontSize = 16.sp, fontWeight = FontWeight.Bold)
            }
            receipt.items.forEach { line ->
                Text("${line.quantity}× ${line.name}", fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
                line.optionsLabel?.let { Text(it, fontSize = 13.sp) }
                line.notes?.let { Text(it, fontSize = 13.sp, fontStyle = FontStyle.Italic) }
            }
            if (canConfirm) {
                Row(Modifier.fillMaxWidth().padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = onReject, enabled = !busy, modifier = Modifier.weight(1f).height(48.dp)) { Text("Recusar") }
                    Button(onClick = onConfirm, enabled = !busy, modifier = Modifier.weight(1f).height(48.dp)) {
                        Text("Confirmar", fontWeight = FontWeight.Bold)
                    }
                }
            } else {
                Text("Aguardando confirmação do gerente.", fontSize = 13.sp)
            }
        }
    }
}

@Composable
private fun ItemRow(item: OrderItem, onDeliver: (() -> Unit)?, busy: Boolean) {
    val cancelled = item.status == ItemStatus.CANCELLED
    val decoration = if (cancelled) TextDecoration.LineThrough else null
    val faded = if (cancelled) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface

    Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(
            "${quantityLabel(item.quantity)}×",
            fontSize = 16.sp,
            fontWeight = FontWeight.Bold,
            color = faded,
            modifier = Modifier.width(36.dp),
        )
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(item.name, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = faded, textDecoration = decoration)
            item.optionsLabel?.let { Text(it, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, textDecoration = decoration) }
            item.notes?.let { Text(it, fontSize = 13.sp, fontStyle = FontStyle.Italic, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            item.status.style()?.let { StatusBadge(it) }
        }
        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(
                money(item.total) ?: item.total,
                fontSize = 15.sp,
                fontWeight = FontWeight.SemiBold,
                color = faded,
                textDecoration = decoration,
            )
            onDeliver?.let {
                FilledTonalButton(onClick = it, enabled = !busy, modifier = Modifier.height(44.dp)) {
                    Text("Entregar", fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun StatusBadge(style: ItemStatusStyle) {
    Surface(shape = RoundedCornerShape(999.dp), color = style.container, contentColor = style.content, modifier = Modifier.padding(top = 2.dp)) {
        Text(style.label, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp))
    }
}

@Composable
private fun Footer(order: TableOrder, onAddItems: () -> Unit) {
    Surface(color = MaterialTheme.colorScheme.surface, shadowElevation = 8.dp) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text("Total da mesa", fontSize = 15.sp, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
                Text(money(order.total) ?: order.total, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
            }
            Button(onClick = onAddItems, shape = RoundedCornerShape(14.dp), modifier = Modifier.fillMaxWidth().height(56.dp)) {
                Icon(painterResource(R.drawable.ic_plus), contentDescription = null, modifier = Modifier.size(20.dp))
                Text("Adicionar itens", fontSize = 17.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 8.dp))
            }
        }
    }
}

@Composable
private fun PeopleDialog(state: OrderState.AskPeople, onOpen: (Int) -> Unit, onDismiss: () -> Unit) {
    var people by rememberSaveable { mutableIntStateOf(2) }

    AlertDialog(
        onDismissRequest = { if (!state.opening) onDismiss() },
        icon = { Icon(painterResource(R.drawable.ic_users), contentDescription = null) },
        title = { Text("Quantas pessoas?") },
        text = {
            Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                    OutlinedIconButton(onClick = { people-- }, enabled = people > 1 && !state.opening, modifier = Modifier.size(52.dp)) {
                        Icon(painterResource(R.drawable.ic_minus), contentDescription = "Menos uma pessoa")
                    }
                    Text(
                        people.toString(),
                        fontSize = 34.sp,
                        fontWeight = FontWeight.ExtraBold,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.width(56.dp).semantics { liveRegion = LiveRegionMode.Polite },
                    )
                    OutlinedIconButton(onClick = { people++ }, enabled = people < 100 && !state.opening, modifier = Modifier.size(52.dp)) {
                        Icon(painterResource(R.drawable.ic_plus), contentDescription = "Mais uma pessoa")
                    }
                }
                state.error?.let {
                    Text(it, color = MaterialTheme.colorScheme.error, fontSize = 14.sp, textAlign = TextAlign.Center)
                }
            }
        },
        confirmButton = {
            Button(onClick = { onOpen(people) }, enabled = !state.opening, modifier = Modifier.height(48.dp)) {
                if (state.opening) {
                    CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                } else {
                    Text("Abrir mesa", fontWeight = FontWeight.Bold)
                }
            }
        },
        dismissButton = { TextButton(onClick = onDismiss, enabled = !state.opening) { Text("Cancelar") } },
    )
}
