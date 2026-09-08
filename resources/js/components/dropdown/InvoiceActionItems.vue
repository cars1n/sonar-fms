<template>
  <div v-if="invoice.published_at && !invoice.parent_uuid && can('invoices.update')" class="p-1">
    <SonarMenuItem is="inertia-link" :href="`/invoices/${invoice.uuid}/portal-notification`" as="button" method="post" :data="{ action: 'resend' }" preserve-scroll>{{ __('Send invoice to guardians again') }}</SonarMenuItem>
    <SonarMenuItem is="inertia-link" :href="`/invoices/${invoice.uuid}/portal-notification`" as="button" method="post" :data="{ action: 'cancel' }" preserve-scroll>{{ __('Cancel pending guardian emails') }}</SonarMenuItem>
  </div>
  <div class="p-1">
    <SonarMenuItem v-if="can('invoices.view') && showView" is="inertia-link" :href="`/invoices/${invoice.uuid}`">
      {{ __('View invoice') }}
    </SonarMenuItem>
    <SonarMenuItem @click.prevent="copy(invoice.invoice_number)">
      {{ __('Copy invoice number') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="can('students.view') && invoice.student" is="inertia-link" :href="`/students/${invoice.student.uuid}`">
      {{ __('View student') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="can('invoices.create') && invoice.student" is="inertia-link" :href="`/students/${invoice.student.uuid}/invoices/create`">
      {{ __('New invoice for student') }}
    </SonarMenuItem>
  </div>
  <div v-if="can('invoices.update') && !invoice.is_void" class="p-1">
    <SonarMenuItem v-if="invoice.published_at" @click.prevent="$emit('editStatus')">
      {{ __('Change status') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="!invoice.published_at" is="inertia-link" :href="`/invoices/${invoice.uuid}/edit`">
      {{ __('Edit') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="!invoice.published_at" is="inertia-link" :href="`/batches/${invoice.batch_id}/edit`">
      {{ __('Edit batch') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="!invoice.published_at" is="inertia-link" :href="`/invoices/${invoice.uuid}/publish`" as="button" method="put" preserve-scroll>
      {{ __('Publish') }}
    </SonarMenuItem>
  </div>
  <div v-if="invoice.published_at && canAny('payments.create', 'invoices.update', 'refunds.create')" class="p-1">
    <SonarMenuItem v-if="can('payments.create') && invoice.amount_due > 0 && !invoice.is_void && invoice.published_at" is="inertia-link" :href="`/payments/create?invoice_uuid=${invoice.uuid}`">
      {{ __('Record payment') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="can('invoices.update')" is="inertia-link" :href="`/invoices/${invoice.uuid}/calculate`" as="button" method="put" preserve-scroll>
      {{ __('Recalculate balances') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="can('invoices.update')" is="inertia-link" :href="`/invoices/${invoice.uuid}/distribute`" as="button" method="post" preserve-scroll>
      {{ __('Redistribute payments') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="can('refunds.create') && invoice.payment_made" is="inertia-link" :href="`/invoices/${invoice.uuid}/refunds/create`">
      {{ __('Record refund') }}
    </SonarMenuItem>
  </div>
  <div class="p-1">
    <SonarMenuItem v-if="can('invoices.view')" is="a" :href="`/invoices/${invoice.uuid}/preview`" target="_blank">
      {{ __('Preview PDF') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="can('invoices.view')" is="a" :href="`/invoices/${invoice.uuid}/pdf`" target="_blank">
      {{ __('Download PDF') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="can('invoices.view')" is="a" :href="`/invoices/${invoice.uuid}/pdf?force=1`" target="_blank">
      {{ __('Regenerate PDF') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="can('invoices.create')" is="inertia-link" :href="`/invoices/${invoice.uuid}/duplicate`">
      {{ __('Duplicate') }}
    </SonarMenuItem>
    <SonarMenuItem v-if="can('invoices.create')" @click.prevent="$emit('convertToTemplate')">
      {{ __('Convert to template') }}
    </SonarMenuItem>
  </div>
</template>

<script>
import { defineComponent } from 'vue'
import SonarMenuItem from '@/components/forms/SonarMenuItem.vue'
import checksPermissions from '@/composition/checksPermissions.js'
import ConvertInvoiceModal from '@/components/modals/ConvertInvoiceModal.vue'
import InvoiceStatusModal from '@/components/modals/InvoiceStatusModal.vue'
import copiesToClipboard from '@/composition/copiesToClipboard.js'

export default defineComponent({
  components: {
    InvoiceStatusModal,
    ConvertInvoiceModal,
    SonarMenuItem,
  },
  props: {
    invoice: Object,
    showView: {
      type: Boolean,
      default: false,
    },
  },
  emits: ['editStatus', 'convertToTemplate'],

  setup () {
    const { can, canAny } = checksPermissions()
    const { copy } = copiesToClipboard()

    return {
      can,
      canAny,
      copy,
    }
  }
})
</script>
