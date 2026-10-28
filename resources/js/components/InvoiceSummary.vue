<template>
  <div class="mb-6">
    <CardSectionHeader>
      {{ __('Summary') }}
    </CardSectionHeader>
    <HelpText class="text-sm mt-1">
      {{ __('Below is the summary of the invoice, including all relevant details that will be displayed when viewing the invoice.') }}
    </HelpText>
  </div>

  <DescriptionList>
    <DescriptionItem>
      <template #dt>
        {{ __('Title') }}
      </template>
      <template #dd>
        {{ invoice.title }}
      </template>
    </DescriptionItem>
    <DescriptionItem>
      <template #dt>
        {{ __('Description') }}
      </template>
      <template #dd>
        {{ invoice.description }}
      </template>
    </DescriptionItem>
    <DescriptionItem>
      <template #dt>
        {{ __('Availability date') }}
      </template>
      <template #dd>
        <span v-if="invoice.available_at" class="flex items-end">
          <span>{{ displayDate(invoice.available_at, 'MMMM D, YYYY H:mm') }}</span>
          <span class="inline-flex ml-3">
            <button class="text-gray-500 dark:text-gray-300 hover:underline focus:outline-none" type="button" @click.prevent="invoice.available_at = null">
              {{ __('Remove') }}
            </button>
          </span>
        </span>
        <span v-else>
          {{ __('No due date.') }}
        </span>
      </template>
    </DescriptionItem>
    <DescriptionItem>
      <template #dt>
        {{ __('Due date') }}
      </template>
      <template #dd>
        <span v-if="invoice.due_at" class="flex items-end">
          <span>{{ displayDate(invoice.due_at, 'MMMM D, YYYY H:mm') }}</span>
          <span class="inline-flex ml-3">
            <button class="text-gray-500 dark:text-gray-300 hover:underline focus:outline-none" type="button" @click.prevent="invoice.due_at = null">
              {{ __('Remove') }}
            </button>
          </span>
        </span>
        <span v-else>
          {{ __('No due date.') }}
        </span>
      </template>
    </DescriptionItem>
    <DescriptionItem v-if="$page.props.school.guardian_notifications_enabled">
      <template #dt>
        {{ __('Notification') }}
      </template>
      <template #dd>
        <span v-if="invoice.notify">
          {{ __('Contacts will be notified in 15 minutes, unless cancelled.') }}
        </span>
        <span v-else>
          {{ __('Manually notify.') }}
        </span>
      </template>
    </DescriptionItem>
    <DescriptionItem>
      <template #dt>
        {{ __('Line items') }}
      </template>
      <template #dd>
        <div
          v-for="item in invoice.items"
          :key="item.id"
          class="flex justify-between py-1"
        >
          <div>
            {{ __(':name x :quantity', { ...item }) }}
          </div>
          <div>
            {{ displayCurrency(item.amount_per_unit * item.quantity) }}
          </div>
        </div>
        <div
          class="flex justify-between font-bold"
          :class="{
            'mt-2 pt-2 border-t border-gray-200 dark:border-gray-400': invoice.items.length > 0
          }"
        >
          <div>{{ __('Subtotal' )}}</div>
          <div>{{ displayCurrency(subtotal) }}</div>
        </div>
      </template>
    </DescriptionItem>
    <DescriptionItem>
      <template #dt>
        {{ __('Scholarships') }}
      </template>
      <template #dd>
        <div
          v-for="item in invoice.scholarships"
          :key="item.id"
          class="flex justify-between py-1"
        >
          <div>
            {{ item.name }}
          </div>
          <div>
            {{ displayCurrency(getItemDiscount(item)) }}
          </div>
        </div>
        <div
          class="font-bold flex justify-between"
          :class="{
            'mt-2 pt-2 border-t border-gray-200 dark:border-gray-400': invoice.scholarships.length > 0
          }"
        >
          <div>{{ __('Scholarship subtotal' )}}</div>
          <div>{{ displayCurrency(scholarshipSubtotal) }}</div>
        </div>
      </template>
    </DescriptionItem>
    <DescriptionItem>
      <template #dt>
        {{ __('Payment schedules') }}
      </template>
      <template #dd>
        <div class="space-y-2 divide-y divide-gray-200 dark:divide-gray-500">
          <div
            v-for="(item, index) in invoice.payment_schedules"
            :key="item.id"
            class="space-y-1"
            :class="{
              'pt-2': index > 0
            }"
          >
            <div class="flex justify-between">
              <div class="font-bold">
                {{ __(':count payments', { count: item.terms.length }) }}
              </div>
              <div class="font-bold">
                {{ displayCurrency(getScheduleTotal(item)) }}
              </div>
            </div>

            <div
              v-for="term in item.terms"
              class="flex justify-between"
            >
              <div>
                {{ term.due_at ? displayDate(term.due_at, 'MMMM D, YYYY H:mm') : __('No due date') }}
              </div>
              <div>
                {{ displayCurrency(term.amount) }}
              </div>
            </div>
          </div>
        </div>
      </template>
    </DescriptionItem>
    <DescriptionItem>
      <template #dt>
        <strong>{{ __('Invoice total') }}</strong>
      </template>
      <template #dd>
        <strong>{{ totalDue }}</strong>
      </template>
    </DescriptionItem>
  </DescriptionList>
</template>

<script>
import { computed } from 'vue'
import CardHeader from '@/components/CardHeader.vue'
import HelpText from '@/components/HelpText.vue'
import Label from '@/components/forms/Label.vue'
import displaysCurrency from '@/composition/displaysCurrency.js'
import Button from '@/components/Button.vue'
import CardSectionHeader from '@/components/CardSectionHeader.vue'
import displaysDate from '@/composition/displaysDate.js'
import invoiceItemForm from '@/composition/invoiceItemForm.js'
import invoiceScholarshipForm from '@/composition/invoiceScholarshipForm.js'
import invoicePaymentScheduleForm from '@/composition/invoicePaymentScheduleForm.js'
import CardWrapper from '@/components/CardWrapper.vue'
import CardPadding from '@/components/CardPadding.vue'
import useSchool from '@/composition/useSchool.js'
import DescriptionList from '@/components/tables/DescriptionList.vue'
import DescriptionItem from '@/components/tables/DescriptionItem.vue'

export default {
  components: {
    DescriptionItem,
    DescriptionList,
    CardPadding,
    CardWrapper,
    CardSectionHeader,
    Button,
    HelpText,
    CardHeader,
    Label,
  },
  props: {
    invoice: {
      type: Object,
      required: true,
    }
  },
  emits: ['close'],

  setup (props) {
    const { school } = useSchool()

    const { timezone, displayDate } = displaysDate()
    const { displayCurrency } = displaysCurrency()
    const total = computed(() => {
      let total = subtotal.value - scholarshipSubtotal.value

      if (total < 0) {
        total = 0
      }

      return total
    })
    const totalDue = computed(() => displayCurrency(total.value))

    // Invoice line items
    const {
      subtotal,
    } = invoiceItemForm(props.invoice)

    // Scholarships
    const {
      scholarshipSubtotal,
      getItemDiscount,
    } = invoiceScholarshipForm(props.invoice)

    // Payment schedules
    const { getScheduleTotal } = invoicePaymentScheduleForm(props.invoice, total)

    return {
      school,
      subtotal,
      displayCurrency,
      displayDate,
      timezone,
      scholarshipSubtotal,
      total,
      totalDue,
      getItemDiscount,
      getScheduleTotal,
    }
  },
}
</script>
