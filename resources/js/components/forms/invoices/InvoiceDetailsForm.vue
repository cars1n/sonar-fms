<template>
  <div class="mb-6">
    <CardSectionHeader>{{ __('Invoice details') }}</CardSectionHeader>
    <HelpText>
      {{ __('These are the general details about the invoice.') }}
    </HelpText>
  </div>
  <Fieldset>
    <div class="grid grid-cols-1 md:grid-cols-6 gap-5">
      <InputWrap :error="localValue.errors.title" class="md:col-span-6">
        <Label for="title" :required="true">{{ __('Title') }}</Label>
        <TemplateBuilder v-model="localValue.title" id="title" required autofocus />
        <HelpText>
          {{ __('Give the invoice a meaningful title that is easily recognizable and descriptive.') }}
        </HelpText>
      </InputWrap>

      <InputWrap class="md:col-span-6">
        <Label for="description">{{ __('Description') }}</Label>
        <Textarea v-model="localValue.description" id="description" />
        <HelpText>
          {{ __('This is a description of the invoice that will be displayed with the invoice.') }}
        </HelpText>
      </InputWrap>

      <InputWrap :error="localValue.errors.invoice_date" class="md:col-span-2">
        <Label for="invoice_date">{{ __('Invoice date') }}</Label>
        <DatePicker v-model="localValue.invoice_date" id="invoice_date" mode="date" />
        <HelpText>
          {{ __('This is the date that will appear on the invoice as the date the invoice was issued.') }}
        </HelpText>
      </InputWrap>

      <InputWrap :error="localValue.errors.available_at" class="md:col-span-2">
        <Label for="available_at">{{ __('Availability') }}</Label>
        <DatePicker v-model="localValue.available_at" id="available_at" />
        <HelpText>
          {{ __("Set a date and time that this invoice is available to the student's guardians or other contacts. Before the configured time, it will only be viewable to admins. This is helpful to use if you want to prepare and preview invoices before actually making them available for the student. The time is based on your current timezone of :timezone. If this timezone is incorrect you can change it in your Personal Settings.", { timezone }) }}
        </HelpText>
      </InputWrap>

      <InputWrap :error="localValue.errors.due_at" class="md:col-span-2">
        <Label for="due_at">{{ __('Due date') }}</Label>
        <DatePicker v-model="localValue.due_at" id="due_at" />
        <HelpText>
          {{ __("Set the date and time that this invoice is due, or don't set one to not have a due date. The time is based on your current timezone of :timezone. If this timezone is incorrect you can change it in your Personal Settings.", { timezone }) }}
        </HelpText>
      </InputWrap>

      <InputWrap :error="localValue.errors.term_id" class="md:col-span-3">
        <Label for="term_id">{{ __('Term') }}</Label>
        <Select v-model="localValue.term_id" id="term_id">
          <option :value="null">{{ __('No term') }}</option>
          <option
            v-for="term in terms"
            :key="term.id"
            :value="term.id"
          >
            {{ term.school_years }} - {{ term.name }}
          </option>
        </Select>
        <HelpText>{{ __('Associating a term with an invoice allows you to group invoices by school term and offers another reporting perspective.') }}</HelpText>
      </InputWrap>

      <InputWrap class="md:col-span-3" :error="localValue.errors.grade_level_adjustment">
        <Label for="grade_level_adjustment">{{ __('Grade level adjustment') }}</Label>
        <Input v-model="localValue.grade_level_adjustment" id="grade_level_adjustment" type="number" />
        <HelpText>{{ __("This will adjust how the student's grade level appears on the generated invoice by year. For example, putting 1 will display a grade 6 student as grade 7. Negative numbers will reduce the grade level.") }}</HelpText>
      </InputWrap>

      <InputWrap class="md:col-span-6">
        <CheckboxWrapper>
          <Checkbox v-model:checked="localValue.notify" />
          <CheckboxText>{{ __('Notify guardians') }}</CheckboxText>
        </CheckboxWrapper>
        <HelpText>
          {{ __("Having this option enabled will automatically queue an email to be sent notifying the appropriate parties of the available invoice. There is a 15-minute delay of sending the notification which allows you to make adjustments, cancel the notification, or delete the invoice all together. If this is not enabled, you may send a notification manually later.") }}
        </HelpText>
      </InputWrap>
    </div>
  </Fieldset>
</template>

<script>
import { defineComponent } from 'vue'
import hasModelValue from '@/composition/hasModelValue.js'
import CardSectionHeader from '@/components/CardSectionHeader.vue'
import HelpText from '@/components/HelpText.vue'
import InputWrap from '@/components/forms/InputWrap.vue'
import CheckboxWrapper from '@/components/forms/CheckboxWrapper.vue'
import DatePicker from '@/components/forms/DatePicker.vue'
import Checkbox from '@/components/forms/Checkbox.vue'
import Textarea from '@/components/forms/Textarea.vue'
import Fieldset from '@/components/forms/Fieldset.vue'
import Select from '@/components/forms/Select.vue'
import Input from '@/components/forms/Input.vue'
import Label from '@/components/forms/Label.vue'
import CheckboxText from '@/components/forms/CheckboxText.vue'
import FadeIn from '@/components/transitions/FadeIn.vue'
import FadeInGroup from '@/components/transitions/FadeInGroup.vue'
import fetchesTerms from '@/composition/fetchesTerms.js'
import displaysDate from '@/composition/displaysDate.js'
import TemplateBuilder from '@/components/forms/TemplateBuilder.vue'

export default defineComponent({
  components: {
    TemplateBuilder,
    FadeInGroup,
    FadeIn,
    CheckboxText,
    Checkbox,
    CheckboxWrapper,
    InputWrap,
    HelpText,
    CardSectionHeader,
    Input,
    Label,
    DatePicker,
    Textarea,
    Fieldset,
    Select,
  },
  props: {
    modelValue: Object,
  },
  emits: ['update:modelValue'],

  setup (props, { emit }) {
    const { localValue } = hasModelValue(props, emit)
    const { terms } = fetchesTerms()
    const { timezone, displayDate } = displaysDate()

    return {
      localValue,
      terms,
      timezone,
      displayDate,
    }
  }
})
</script>
